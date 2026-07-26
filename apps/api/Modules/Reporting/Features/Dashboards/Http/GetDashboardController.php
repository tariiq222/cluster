<?php

namespace Modules\Reporting\Features\Dashboards\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Reporting\Features\GetAuthorizedDashboard\Handler\GetAuthorizedDashboardHandler;
use Modules\Reporting\Http\ReportingApi;

final class GetDashboardController
{
    public function __construct(private readonly ResolveDevelopmentFixturePrincipal $principalResolver, private readonly GetAuthorizedDashboardHandler $dashboards) {}

    public function __invoke(Request $request, string $dashboardId): JsonResponse
    {
        $correlationId = ReportingApi::correlationId($request);
        if ($correlationId === null) {
            return ReportingApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $principal = ReportingApi::principalOrProblem($request, $this->principalResolver, $correlationId);
        if ($principal instanceof JsonResponse) {
            return $principal;
        }

        return ReportingApi::response($this->dashboards->handle($dashboardId, $principal, $request->query('scope_id')), 200, $correlationId);
    }
}
