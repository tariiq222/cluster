<?php

namespace Modules\Organization\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class OrganizationApi
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
    public static function data(array $data, int $status, string $correlationId, ?int $lockVersion = null): JsonResponse
    {
        $headers = ['X-Correlation-ID' => $correlationId];
        if ($lockVersion !== null) {
            $headers['ETag'] = '"'.$lockVersion.'"';
        }

        return response()->json(['data' => $data], $status)->withHeaders($headers);
    }

    public static function problem(
        int $status,
        string $type,
        string $title,
        string $detail,
        ?string $correlationId = null,
    ): JsonResponse {
        $response = response()->json([
            'type' => "https://cluster.example/problems/{$type}",
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $status)->header('Content-Type', 'application/problem+json');

        return $correlationId === null ? $response : $response->header('X-Correlation-ID', $correlationId);
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  array{user_id: string, facility_id: string}  $principal
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public static function cloudEvent(
        string $type,
        string $subject,
        string $correlationId,
        string $tenantId,
        string $resourceKey,
        array $resource,
        array $principal,
        array $details = [],
    ): array {
        return [
            'specversion' => '1.0',
            'id' => Str::uuid7()->toString(),
            'source' => '/organization',
            'type' => $type,
            'subject' => $subject,
            'time' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'datacontenttype' => 'application/json',
            'correlationid' => $correlationId,
            'data' => [
                $resourceKey => $resource,
                ...$details,
                'access_context' => [
                    'subject_id' => $principal['user_id'],
                    'tenant_id' => $tenantId,
                    'organization_unit_ids' => [],
                    'roles' => ['bootstrap_admin'],
                    'clearance' => 'internal',
                    'break_glass' => false,
                    'correlation_id' => $correlationId,
                ],
                'classification' => 'internal',
            ],
        ];
    }

    public static function isUuidV7(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1;
    }
}
