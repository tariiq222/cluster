<?php

namespace Modules\Search\Features\Search\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Search\Features\SearchAccessibleRecords\Handler\SearchAccessibleRecordsHandler;
use Modules\Search\Http\SearchApi;

final class SearchController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly SearchAccessibleRecordsHandler $search,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = SearchApi::correlationId($request);
        if ($correlationId === null) {
            return SearchApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $principal = SearchApi::principalOrProblem($request, $this->principalResolver, $correlationId);
        if ($principal instanceof JsonResponse) {
            return $principal;
        }

        $input = $request->json()->all();
        $validator = Validator::make($input, [
            'q' => ['required', 'string', 'min:1', 'max:256'],
            'scope_id' => ['sometimes', 'string', 'max:128'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'min:1', 'max:2048'],
            'type' => ['sometimes', 'string', 'min:1', 'max:64'],
            'status' => ['sometimes', 'string', 'min:1', 'max:64'],
        ]);
        if ($validator->fails() || array_diff(array_keys($input), ['q', 'scope_id', 'limit', 'cursor', 'type', 'status']) !== []) {
            return SearchApi::problem(400, 'invalid-search-query', 'Bad Request', 'The search query is invalid.', $correlationId);
        }
        $validated = $validator->validated();
        $limit = (int) ($validated['limit'] ?? 25);
        try {
            $result = $this->search->handle(
                $principal,
                (string) $validated['q'],
                $validated['scope_id'] ?? null,
                $limit,
                $validated['cursor'] ?? null,
                $validated['type'] ?? null,
                $validated['status'] ?? null,
            );
        } catch (InvalidArgumentException) {
            return SearchApi::problem(400, 'invalid-search-query', 'Bad Request', 'The search query is invalid.', $correlationId);
        }

        return SearchApi::response($result, 200, $correlationId);
    }
}
