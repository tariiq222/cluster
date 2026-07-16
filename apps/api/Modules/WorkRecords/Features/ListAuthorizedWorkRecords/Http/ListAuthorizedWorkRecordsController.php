<?php

namespace Modules\WorkRecords\Features\ListAuthorizedWorkRecords\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\WorkRecords\Features\ListAuthorizedWorkRecords\Handler\ListAuthorizedWorkRecordsHandler;

final class ListAuthorizedWorkRecordsController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly ListAuthorizedWorkRecordsHandler $handler,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = $this->correlationId($request);
        if ($correlationId === null) {
            return $this->problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }

        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return $this->problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }

        $query = $request->query();
        $validator = Validator::make($query, [
            'cursor' => ['sometimes', 'string', 'min:1', 'max:2048'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'classification' => ['sometimes', 'string', 'in:public,internal,confidential,top_secret'],
        ]);
        if ($validator->fails() || array_diff(array_keys($query), ['cursor', 'limit', 'classification']) !== []) {
            return $this->problem(400, 'invalid-pagination', 'Bad Request', 'The collection parameters are invalid.', $correlationId);
        }

        $validated = $validator->validated();
        $limit = (int) ($validated['limit'] ?? 25);
        try {
            $page = $this->handler->handle(
                $principal,
                $validated['cursor'] ?? null,
                $limit,
                $validated['classification'] ?? null,
            );
        } catch (InvalidArgumentException) {
            return $this->problem(400, 'invalid-pagination', 'Bad Request', 'The collection parameters are invalid.', $correlationId);
        }

        $response = response()->json($page)->header('X-Correlation-ID', $correlationId);
        if ($page['next_cursor'] !== null) {
            $nextQuery = ['cursor' => $page['next_cursor'], 'limit' => $limit];
            if (isset($validated['classification'])) {
                $nextQuery['classification'] = $validated['classification'];
            }
            $response->header('Link', '</api/v1/work-records?'.http_build_query($nextQuery, '', '&', PHP_QUERY_RFC3986).'>; rel="next"');
        }

        return $response;
    }

    private function correlationId(Request $request): ?string
    {
        $value = $request->header('X-Correlation-ID');

        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1
            ? $value
            : null;
    }

    private function problem(int $status, string $type, string $title, string $detail, ?string $correlationId = null): JsonResponse
    {
        $response = response()->json([
            'type' => "https://cluster.example/problems/{$type}",
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $status)->header('Content-Type', 'application/problem+json');

        return $correlationId === null ? $response : $response->header('X-Correlation-ID', $correlationId);
    }
}
