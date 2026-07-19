<?php

namespace Modules\Reporting\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Reporting\Features\DownloadExportArtifact\Handler\DownloadExportArtifactHandler;

final class DownloadExportController
{
    public function __construct(private readonly ResolveDevelopmentFixturePrincipal $principalResolver, private readonly DownloadExportArtifactHandler $downloads) {}

    public function __invoke(Request $request, string $exportId): JsonResponse
    {
        $correlationId = ReportingApi::correlationId($request);
        if ($correlationId === null) {
            return ReportingApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $principal = ReportingApi::principalOrProblem($request, $this->principalResolver, $correlationId);
        if ($principal instanceof JsonResponse) {
            return $principal;
        }
        $result = $this->downloads->handle($exportId, $principal);

        return $result === null
            ? ReportingApi::problem(404, 'export-not-found', 'Not Found', 'The export is not available.', $correlationId)
            : ReportingApi::response($result, 200, $correlationId);
    }
}
