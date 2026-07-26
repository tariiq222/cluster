<?php

declare(strict_types=1);

namespace Shared\Http;

use Illuminate\Http\JsonResponse;

/**
 * Canonical `application/problem+json` envelope for the API surface.
 *
 * Producer middlewares and controllers emit problems through this helper so
 * status, `type`, `title`, and the correlation id travel in a single shape.
 * Callers may carry additional fields (extension members, `errors`,
 * `instance`) through the typed `$extra` map. When `$correlationId` is a
 * non-empty string, the body carries the `correlation_id` field and the
 * `X-Correlation-ID` response header; otherwise the body omits the field and
 * the header is unset so callers that already set the header (or that want to
 * generate a server-side value themselves) keep control.
 */
final class ProblemEnvelope
{
    /**
     * @param  array<string, mixed>  $extra  optional extension members merged after the canonical fields
     */
    public static function make(int $status, string $type, string $title, ?string $correlationId = null, array $extra = []): JsonResponse
    {
        $body = ['type' => "https://cluster.example/problems/{$type}", 'title' => $title, 'status' => $status];
        if ($correlationId !== null && $correlationId !== '') {
            $body['correlation_id'] = $correlationId;
        }

        $response = response()->json(
            $body + $extra,
            $status,
            ['Content-Type' => 'application/problem+json'],
        );

        if ($correlationId !== null && $correlationId !== '') {
            $response->header('X-Correlation-ID', $correlationId);
        }

        return $response;
    }
}
