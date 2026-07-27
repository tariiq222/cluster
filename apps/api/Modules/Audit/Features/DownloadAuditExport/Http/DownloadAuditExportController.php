<?php

declare(strict_types=1);

namespace Modules\Audit\Features\DownloadAuditExport\Http;

use Illuminate\Http\Request;
use Modules\Audit\Features\DownloadAuditExport\Handler\DownloadAuditExportHandler;
use Modules\Audit\Http\AuditApi;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/audit/exports/{exportId}/download
 *
 * Wraps the {@see DownloadAuditExportHandler} with HTTP-level concerns:
 * correlation id validation and session presence. The handler is the
 * authoritative source for authorization, redaction, snapshot bounds,
 * and the per-attempt Audit activity. The controller never reads or
 * persists export bytes.
 */
final class DownloadAuditExportController
{
    public function __construct(
        private readonly DownloadAuditExportHandler $handler,
    ) {}

    public function __invoke(Request $request, string $exportId): Response
    {
        $correlationId = AuditApi::correlationId($request);
        if ($correlationId === null) {
            return AuditApi::problem(
                400,
                'invalid-correlation-id',
                'Bad Request',
                'X-Correlation-ID must be a lowercase UUIDv7.',
            );
        }

        return $this->handler->handle($request, $exportId, $correlationId);
    }
}
