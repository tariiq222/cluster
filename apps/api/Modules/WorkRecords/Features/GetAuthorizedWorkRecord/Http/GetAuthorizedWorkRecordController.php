<?php

namespace Modules\WorkRecords\Features\GetAuthorizedWorkRecord\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\WorkRecords\Features\GetAuthorizedWorkRecord\Handler\GetAuthorizedWorkRecordHandler;

final class GetAuthorizedWorkRecordController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly GetAuthorizedWorkRecordHandler $handler,
    ) {}

    public function __invoke(Request $request, string $recordId): JsonResponse
    {
        $correlationId = $this->correlationId($request);
        if ($correlationId === null) {
            return $this->problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }

        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return $this->problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }

        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $recordId) !== 1) {
            return $this->problem(400, 'invalid-work-record-id', 'Bad Request', 'recordId must be a lowercase UUIDv7.', $correlationId);
        }

        $record = $this->handler->handle($principal, $recordId);
        if ($record === null) {
            return $this->problem(
                404,
                'work-record-unavailable',
                'Not Found',
                'لا يمكنك فتح هذا الطلب أو لم يعد متاحاً.',
                $correlationId,
            );
        }

        return response()->json(['data' => $record])
            ->withHeaders([
                'X-Correlation-ID' => $correlationId,
                'ETag' => '"'.$record['lock_version'].'"',
            ]);
    }

    private function correlationId(Request $request): ?string
    {
        $value = $request->header('X-Correlation-ID');

        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1
            ? $value
            : null;
    }

    private function problem(int $status, string $type, string $title, string $detail, ?string $correlationId = null): JsonResponse
    {
        $response = response()->json([
            'type' => "https://cluster.example/problems/{$type}",
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $status)->header('Content-Type', 'application/problem+json');

        return $correlationId === null ? $response : $response->header('X-Correlation-ID', $correlationId);
    }
}
