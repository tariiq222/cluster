<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Reporting\Features\RefreshReportingProjection\Handler\RefreshReportingProjectionHandler;
use Modules\Search\Features\IndexSourceEvent\Handler\IndexSourceEventHandler;
use Symfony\Component\HttpFoundation\Response;

final class ProjectWorkRecordReadModels
{
    public const REPORT_ID = '019f7000-0000-7000-8000-000000000901';

    public function __construct(
        private readonly IndexSourceEventHandler $search,
        private readonly RefreshReportingProjectionHandler $reporting,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $response instanceof JsonResponse || $response->getStatusCode() >= 300) {
            return $response;
        }

        $body = $response->getData(true);
        $record = is_array($body['data'] ?? null) ? $body['data'] : null;
        if ($record === null || ! is_string($record['id'] ?? null)) {
            return $response;
        }

        $payload = $record['payload'] ?? [];
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        $payload = is_array($payload) ? $payload : [];
        $scopeId = $record['owner_facility_id'] ?? ($record['owner']['facility_id'] ?? null);
        $event = [
            'source_module' => 'work-records',
            'source_type' => 'work_record',
            'source_id' => $record['id'],
            'source_version' => (string) ($record['lock_version'] ?? 1),
            'scope_id' => $scopeId,
            'classification' => $record['classification'] ?? 'internal',
            'indexable' => [
                'title' => $payload['title'] ?? null,
                'excerpt' => $payload['description'] ?? null,
            ],
        ];
        $this->search->handle($event);
        $this->reporting->handle([
            ...$event,
            'report_id' => self::REPORT_ID,
            'title' => $payload['title'] ?? null,
            'safe_data' => ['status' => $record['status'] ?? null],
        ]);

        return $response;
    }
}
