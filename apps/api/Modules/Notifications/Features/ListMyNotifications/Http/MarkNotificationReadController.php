<?php

namespace Modules\Notifications\Features\ListMyNotifications\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

final class MarkNotificationReadController
{
    public function __construct(private readonly ResolveDevelopmentFixturePrincipal $principalResolver) {}

    public function __invoke(Request $request, string $notificationId): JsonResponse
    {
        $correlationId = (string) $request->header('X-Correlation-ID');
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $correlationId) !== 1) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $correlationId);
        }
        if (! is_string($request->header('Idempotency-Key')) || $request->header('Idempotency-Key') === '') {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $correlationId);
        }
        $notification = DB::table('notifications')->where('id', $notificationId)->where('recipient_user_id', $principal['user_id'])->first();
        if ($notification === null) {
            return $this->problem(404, 'resource-not-found', 'The notification is not available.', $correlationId);
        }
        DB::table('notifications')->where('id', $notificationId)->where('recipient_user_id', $principal['user_id'])->update(['is_read' => true, 'updated_at' => now('UTC')]);

        return response()->json(['data' => ['id' => $notificationId, 'is_read' => true]])->header('X-Correlation-ID', $correlationId);
    }

    private function problem(int $status, string $type, string $detail, ?string $correlationId = null): JsonResponse
    {
        $response = response()->json(['type' => "https://cluster.example/problems/{$type}", 'title' => $status === 401 ? 'Unauthorized' : ($status === 404 ? 'Not Found' : 'Bad Request'), 'status' => $status, 'detail' => $detail], $status)->header('Content-Type', 'application/problem+json');

        return $correlationId === null ? $response : $response->header('X-Correlation-ID', $correlationId);
    }
}
