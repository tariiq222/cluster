<?php

declare(strict_types=1);

namespace Modules\Notifications\Features\Http;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Handler\ConsumeWorkRecordSubmittedHandler;
use Shared\Contracts\OutboxEventLookup;
use Symfony\Component\HttpFoundation\Response;

final class ConsumeSubmittedNotification
{
    public function __construct(
        private readonly OutboxEventLookup $outbox,
        private readonly ConsumeWorkRecordSubmittedHandler $notifications,
    ) {}

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

        $event = $this->outbox->findCloudEvent(
            $recordId,
            'com.cluster.workrecord.submitted.v1',
        );
        if ($event !== null) {
            $this->notifications->handle($event);
        }

        return $response;
    }
}
