<?php

namespace Modules\Organization\Features\TemporaryAssignment\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use UnexpectedValueException;

final class TemporaryAssignmentApi
{
    /** @var list<string> */
    private const RESPONSE_FIELDS = [
        'id',
        'person_id',
        'organization_unit_id',
        'capability_codes',
        'start_at',
        'end_at',
        'status',
        'reason',
        'approved_by_user_id',
        'revoked_at',
        'revoke_reason',
        'lock_version',
    ];

    /** @param array<string, mixed> $temporaryAssignment */
    public static function resource(
        array $temporaryAssignment,
        int $status,
        string $correlationId,
    ): JsonResponse {
        return response()->json([
            'data' => self::minimize($temporaryAssignment),
        ], $status)->withHeaders(self::resourceHeaders($temporaryAssignment, $correlationId));
    }

    /** @param array<string, mixed> $temporaryAssignment */
    public static function notModified(array $temporaryAssignment, string $correlationId): Response
    {
        return response('', 304)->withHeaders(self::resourceHeaders($temporaryAssignment, $correlationId));
    }

    /** @param array<string, mixed> $temporaryAssignment */
    public static function requestCacheMatches(Request $request, array $temporaryAssignment): bool
    {
        $value = $request->header('If-None-Match');
        if (! is_string($value) || strlen($value) > 4096) {
            return false;
        }
        $etag = self::representationEtag($temporaryAssignment);

        return in_array($etag, array_map('trim', explode(',', $value)), true)
            || trim($value) === '*';
    }

    /**
     * @param  array{items: list<array<string, mixed>>, next_cursor: string|null}  $page
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public static function page(array $page): array
    {
        return [
            'items' => array_map(self::minimize(...), $page['items']),
            'next_cursor' => $page['next_cursor'],
        ];
    }

    /** @param array<string, mixed> $temporaryAssignment */
    /** @return array<string, string> */
    private static function resourceHeaders(array $temporaryAssignment, string $correlationId): array
    {
        $version = $temporaryAssignment['lock_version'] ?? null;
        if (! is_int($version) || $version < 1) {
            throw new UnexpectedValueException('The temporary assignment resource version is unavailable.');
        }

        return [
            'X-Correlation-ID' => $correlationId,
            'ETag' => self::representationEtag($temporaryAssignment),
            'X-Resource-Version' => '"'.$version.'"',
        ];
    }

    /** @param array<string, mixed> $temporaryAssignment */
    private static function representationEtag(array $temporaryAssignment): string
    {
        $etag = $temporaryAssignment['representation_etag'] ?? null;
        if (! is_string($etag)
            || strlen($etag) > 255
            || preg_match('/\AW\/"[A-Za-z0-9._:-]+"\z/', $etag) !== 1) {
            throw new UnexpectedValueException('The temporary assignment cache validator is unavailable.');
        }

        return $etag;
    }

    /** @param array<string, mixed> $temporaryAssignment */
    /** @return array<string, mixed> */
    private static function minimize(array $temporaryAssignment): array
    {
        $state = $temporaryAssignment['state'] ?? null;
        $temporaryAssignment['status'] = $state === 'pending' ? 'scheduled' : $state;
        $temporaryAssignment['revoke_reason'] = $temporaryAssignment['revocation_reason'] ?? null;
        $resource = [];
        foreach (self::RESPONSE_FIELDS as $field) {
            if (array_key_exists($field, $temporaryAssignment)) {
                $resource[$field] = $temporaryAssignment[$field];
            }
        }

        return $resource;
    }
}
