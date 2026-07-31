<?php

namespace Modules\Notifications\Features\ListMyNotifications\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Shared\Http\ProblemEnvelope;

final class MarkNotificationReadController
{
    public function __construct(private readonly ResolveDevelopmentFixturePrincipal $principalResolver) {}

    public function __invoke(Request $request, string $notificationId): JsonResponse
    {
        $correlationId = (string) $request->header('X-Correlation-ID');
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $correlationId) !== 1) {
            return ProblemEnvelope::make(
                400,
                'invalid-correlation-id',
                'Bad Request',
                Str::uuid7()->toString(),
                ['detail' => 'X-Correlation-ID must be a lowercase UUIDv7.'],
            );
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return ProblemEnvelope::make(
                401,
                'authentication-required',
                'Unauthorized',
                $correlationId,
                ['detail' => 'Authentication is required.'],
            );
        }
        // The row lookup is scoped to the caller's user_id so the recipient
        // is the only one who can ever observe a hit; the same predicate is
        // carried into the UPDATE so authorization is enforced before any
        // state changes happen inside the transaction.
        $notification = DB::table('notifications')->where('id', $notificationId)->where('recipient_user_id', $principal['user_id'])->first();
        if ($notification === null) {
            return ProblemEnvelope::make(
                404,
                'resource-not-found',
                'Not Found',
                $correlationId,
                ['detail' => 'The notification is not available.'],
            );
        }
        DB::transaction(function () use ($notificationId, $principal): void {
            $now = now('UTC');
            DB::table('notifications')
                ->where('id', $notificationId)
                ->where('recipient_user_id', $principal['user_id'])
                ->update([
                    'is_read' => true,
                    'status' => 'read',
                    'updated_at' => $now,
                ]);
            DB::table('notification_recipients')
                ->where('notification_id', $notificationId)
                ->where('recipient_user_id', $principal['user_id'])
                ->whereNull('read_at')
                ->update([
                    'status' => 'read',
                    'read_at' => $now,
                    'updated_at' => $now,
                ]);
        });

        return response()->json(['data' => ['id' => $notificationId, 'is_read' => true]])->header('X-Correlation-ID', $correlationId);
    }
}
