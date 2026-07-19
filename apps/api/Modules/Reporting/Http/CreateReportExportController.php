<?php

namespace Modules\Reporting\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Reporting\Features\ExportAuthorizedReport\Handler\ExportAuthorizedReportHandler;

final class CreateReportExportController
{
    public function __construct(private readonly ResolveDevelopmentFixturePrincipal $principalResolver, private readonly ExportAuthorizedReportHandler $exports) {}

    public function __invoke(Request $request, string $reportId): JsonResponse
    {
        $correlationId = ReportingApi::correlationId($request);
        if ($correlationId === null) {
            return ReportingApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $principal = ReportingApi::principalOrProblem($request, $this->principalResolver, $correlationId);
        if ($principal instanceof JsonResponse) {
            return $principal;
        }
        $input = $request->json()->all();
        $validator = Validator::make($input, ['format' => ['required', 'string', 'in:csv,xlsx,pdf'], 'scope_id' => ['sometimes', 'string', 'max:128']]);
        if ($validator->fails() || array_diff(array_keys($input), ['format', 'scope_id']) !== []) {
            return ReportingApi::problem(400, 'invalid-export-request', 'Bad Request', 'The export request is invalid.', $correlationId);
        }
        $validated = $validator->validated();

        return ReportingApi::response($this->exports->handle($reportId, $principal, (string) $validated['format'], $validated['scope_id'] ?? null), 202, $correlationId);
    }
}
