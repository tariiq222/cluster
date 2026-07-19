<?php

namespace Modules\Reporting\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

final class ReportingApi
{
    /** @return array{user_id: string, facility_id: string}|JsonResponse */
    public static function principalOrProblem(Request $request, ResolveDevelopmentFixturePrincipal $resolver, string $correlationId): array|JsonResponse
    {
        $principal = $resolver->resolve($request);

        return $principal ?? self::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
    }

    public static function correlationId(Request $request): ?string
    {
        $value = $request->header('X-Correlation-ID');

        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1 ? $value : null;
    }

    public static function problem(int $status, string $type, string $title, string $detail, ?string $correlationId = null): JsonResponse
    {
        $response = response()->json(['type' => "https://cluster.example/problems/{$type}", 'title' => $title, 'status' => $status, 'detail' => $detail], $status)
            ->header('Content-Type', 'application/problem+json');

        return $correlationId === null ? $response : $response->header('X-Correlation-ID', $correlationId);
    }

    /** @param array<string, mixed> $body */
    public static function response(array $body, int $status, string $correlationId): JsonResponse
    {
        return response()->json($body, $status)->header('X-Correlation-ID', $correlationId);
    }
}
