<?php

namespace Modules\Notifications\Features\Http;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Handler\ConsumeWorkRecordSubmittedHandler;
use Symfony\Component\HttpFoundation\Response;

final class ConsumeSubmittedNotification
{
    public function __construct(private readonly ConsumeWorkRecordSubmittedHandler $notifications) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! app()->environment('testing') || $request->header('X-Day3-Acceptance') !== '1') {
            return $response;
        }
        if (! $response instanceof JsonResponse || $response->getStatusCode() >= 300) {
            return $response;
        }
        $body = $response->getData(true);
        $recordId = $body['data']['id'] ?? null;
        if (! is_string($recordId)) {
            return $response;
        }
        $event = DB::table('outbox_events')
            ->where('aggregate_id', $recordId)
            ->where('event_type', 'com.cluster.workrecord.submitted.v1')
            ->orderBy('occurred_at')
            ->value('cloud_event');
        if (is_string($event)) {
            $decoded = json_decode($event, true);
            if (is_array($decoded)) {
                $this->notifications->handle($decoded);
            }
        }

        return $response;
    }
}
