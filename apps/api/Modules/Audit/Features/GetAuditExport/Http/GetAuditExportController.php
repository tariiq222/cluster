<?php

declare(strict_types=1);

namespace Modules\Audit\Features\GetAuditExport\Http;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Audit\Contracts\AuditEventInput;
use Modules\Audit\Http\AuditApi;
use Modules\Audit\Infrastructure\Persistence\AuditExportRepository;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolvePrincipalContext;

/**
 * GET /api/v1/audit/exports/{exportId}
 *
 * Reads a single export descriptor. Re-authorizes the read every time.
 * Principal/scope mismatch is concealed as a 404 problem with the same
 * bytes the missing-export case returns. The descriptor is returned
 * with a strong ETag carrying the export id (the only mutating
 * transition — `ready` → `expired` — increments `lock_version`).
 */
final class GetAuditExportController
{
    public function __construct(
        private readonly ResolvePrincipalContext $principals,
        private readonly DecideAccess $access,
        private readonly AuditExportRepository $exports,
    ) {}

    public function __invoke(Request $request, string $exportId): JsonResponse
    {
        $correlationId = AuditApi::correlationId($request);
        $principal = $this->principals->resolve($request);
        if ($principal === null) {
            return $this->unauthorized($correlationId);
        }
        if ($correlationId === null) {
            return AuditApi::problem(
                400,
                'invalid-correlation-id',
                'Bad Request',
                'X-Correlation-ID must be a lowercase UUIDv7.',
            );
        }

        try {
            AuditEventInput::assertUuidV7($exportId, 'exportId');
        } catch (\InvalidArgumentException) {
            return $this->notFound($correlationId);
        }

        $scope = AuditApi::scope($principal);

        // Read the row first so authorization can deny without disclosing
        // existence. The decision runs against `audit_export` facts.
        $row = $this->exports->find($exportId);
        if ($row === null
            || (string) $row->principal_id !== $principal->userId
            || $row->facility_id !== $scope['facility_id']) {
            return $this->notFound($correlationId);
        }

        // First observation CAS: if the persisted snapshot is past its
        // expiry, transition ready→expired on (id, lock_version) and
        // re-read so the response carries the same ETag the download
        // handler will use. Concurrent callers race on the predicate;
        // exactly one row update commits.
        $row = $this->advanceExpiryIfDue($row);

        $decision = $this->access->decide(
            AuditApi::actor($principal, $scope, $correlationId),
            'audit.event.export',
            new RecordFacts(
                ownerFacilityId: $row->facility_id === null ? null : (string) $row->facility_id,
                resourceType: 'audit_export',
                classification: 'internal',
                organizationUnitId: count($scope['organization_unit_ids']) === 1
                    ? $scope['organization_unit_ids'][0]
                    : null,
                recordId: (string) $row->id,
                sharedUnitIds: $scope['organization_unit_ids'],
            ),
        );
        if (! $decision->isAllowed()) {
            return $this->notFound($correlationId);
        }

        $query = $this->decodeQuery((string) $row->query);

        $descriptor = [
            'id' => (string) $row->id,
            'principal_id' => (string) $row->principal_id,
            'facility_id' => $row->facility_id === null ? null : (string) $row->facility_id,
            'query' => $query,
            'format' => (string) $row->format,
            'snapshot_recorded_at' => $this->apiTimestamp((string) $row->snapshot_recorded_at),
            'status' => (string) $row->status,
            'event_count' => (int) $row->event_count,
            'expires_at' => $this->apiTimestamp((string) $row->expires_at),
            'created_at' => $this->apiTimestamp((string) $row->created_at),
        ];

        // ETag embeds the lock_version so the unique mutating
        // transition (ready→expired) changes it for cached clients.
        $etag = (string) $row->id.':'.(string) $row->lock_version;

        return AuditApi::response(['data' => $descriptor], $correlationId)
            ->header('ETag', '"'.$etag.'"');
    }

    /**
     * Move a still-`ready` descriptor whose `expires_at` is in the past
     * to `expired` exactly once. The CAS predicate on
     * `(id, lock_version)` ensures concurrent first observations
     * advance the row at most once; the loser reloads and observes the
     * new state.
     */
    private function advanceExpiryIfDue(object $row): object
    {
        if ((string) $row->status !== AuditExportRepository::STATUS_READY) {
            return $row;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = new DateTimeImmutable((string) $row->expires_at, new DateTimeZone('UTC'));
        if ($expiresAt > $now) {
            return $row;
        }
        $expected = (int) $row->lock_version;
        $this->exports->markExpired((string) $row->id, $expected);
        $fresh = $this->exports->find((string) $row->id);

        return $fresh ?? $row;
    }


    private function apiTimestamp(string $value): string
    {
        return (new \DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    /** @return array<string, mixed> */
    private function decodeQuery(string $raw): array
    {
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function unauthorized(?string $correlationId): JsonResponse
    {
        return AuditApi::problem(
            401,
            'authentication-required',
            'Unauthorized',
            'Authentication is required.',
            $correlationId,
        );
    }

    private function notFound(?string $correlationId): JsonResponse
    {
        return AuditApi::problem(
            404,
            'audit-export-not-found',
            'Not Found',
            'The audit export was not found.',
            $correlationId,
        );
    }
}
