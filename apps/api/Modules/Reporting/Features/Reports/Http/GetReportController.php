<?php

namespace Modules\Reporting\Features\Reports\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Reporting\Features\RunAuthorizedReport\Handler\RunAuthorizedReportHandler;
use Modules\Reporting\Http\ReportingApi;

final class GetReportController
{
    public function __construct(private readonly ResolveDevelopmentFixturePrincipal $principalResolver, private readonly RunAuthorizedReportHandler $reports) {}

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

        return ReportingApi::response($this->reports->handle($reportId, $principal, $request->query('scope_id')), 200, $correlationId);
    }
}
