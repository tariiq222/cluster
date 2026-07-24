<?php

namespace Modules\Tasks\Features\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

trait TaskHttpSupport
{
    private function principal(Request $request, ResolveDevelopmentFixturePrincipal $resolver): ?array
    {
        return $resolver->resolve($request);
    }

    private function correlation(Request $request): ?string
    {
        $value = $request->header('X-Correlation-ID');

        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1 ? $value : null;
    }

    private function commandHeaders(Request $request): array|string
    {
        $key = $request->header('Idempotency-Key');

        return is_string($key) && preg_match('/\A[\x21-\x7E]{1,255}\z/', $key) === 1 ? $key : '';
    }

    private function response(array $data, int $status, string $correlation, ?int $version = null): JsonResponse
    {
        $response = response()->json(['data' => $data], $status)->header('X-Correlation-ID', $correlation);
        if ($version !== null) {
            $response->header('ETag', '"'.$version.'"');
        }

        return $response;
    }

    private function problem(int $status, string $type, string $detail, ?string $correlation = null): JsonResponse
    {
        $response = response()->json([
            'type' => 'https://cluster.example/problems/'.$type,
            'title' => match ($status) {
                400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 409 => 'Conflict', 412 => 'Precondition Failed', default => 'Unprocessable Content'
            },
            'status' => $status,
            'detail' => $detail,
        ], $status)->header('Content-Type', 'application/problem+json');

        return $correlation === null ? $response : $response->header('X-Correlation-ID', $correlation);
    }

    private function versionFromMatch(Request $request): ?int
    {
        $raw = $request->header('If-Match');
        if (! is_string($raw) || preg_match('/\A"([0-9]+)"\z/', $raw, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    protected function utcDateTime(string $value): string
    {
        if ($value === '') {
            return Carbon::now()->toIso8601String();
        }

        return Carbon::parse($value)->utc()->toIso8601String();
    }
}
