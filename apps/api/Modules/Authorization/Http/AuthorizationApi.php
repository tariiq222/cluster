<?php

namespace Modules\Authorization\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthorizationApi
{
    public static function correlationId(Request $request): ?string
    {
        $value = $request->header('X-Correlation-ID');

        return is_string($value) && self::isUuidV7($value) ? $value : null;
    }

    public static function idempotencyKey(Request $request): ?string
    {
        $value = $request->header('Idempotency-Key');

        return is_string($value) && preg_match('/\A[\x21-\x7E]{1,255}\z/', $value) === 1 ? $value : null;
    }

    public static function ifMatch(Request $request): ?int
    {
        $value = $request->header('If-Match');
        if (! is_string($value) || preg_match('/\A"([1-9][0-9]*)"\z/', $value, $matches) !== 1) {
            return null;
        }

        $version = (int) $matches[1];

        return $version > 0 && (string) $version === $matches[1] ? $version : null;
    }

    public static function isMergePatch(Request $request): bool
    {
        $contentType = $request->header('Content-Type');

        return is_string($contentType)
            && strtolower(trim(explode(';', $contentType, 2)[0])) === 'application/merge-patch+json';
    }

    /** @param array<string, mixed> $data */
    public static function resource(array $data, int $status, string $correlationId, ?int $version = null): JsonResponse
    {
        $response = response()->json(['data' => $data], $status)->header('X-Correlation-ID', $correlationId);
        if ($version !== null) {
            $response->header('ETag', '"'.$version.'"');
        }

        return $response;
    }

    /** @param array{items: list<array<string,mixed>>, next_cursor: string|null} $page */
    public static function collection(array $page, string $correlationId, ?string $link = null): JsonResponse
    {
        $response = response()->json($page)->header('X-Correlation-ID', $correlationId);
        if ($link !== null) {
            $response->header('Link', $link);
        }

        return $response;
    }

    public static function problem(int $status, string $type, string $title, string $detail, ?string $correlationId = null): JsonResponse
    {
        $response = response()->json([
            'type' => "https://cluster.example/problems/{$type}",
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $status)->header('Content-Type', 'application/problem+json');

        return $correlationId === null ? $response : $response->header('X-Correlation-ID', $correlationId);
    }

    public static function isUuidV7(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1;
    }
}
