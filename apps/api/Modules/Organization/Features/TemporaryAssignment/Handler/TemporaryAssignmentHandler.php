<?php

namespace Modules\Organization\Features\TemporaryAssignment\Handler;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Modules\Organization\Features\TemporaryAssignment\Contracts\ValidateTemporaryAssignmentCapabilities;
use Modules\Organization\Features\TemporaryAssignment\Events\BuildTemporaryAssignmentEvent;
use Modules\Organization\Features\TemporaryAssignment\Exceptions\TemporaryAssignmentIdempotencyConflict;
use Modules\Organization\Infrastructure\Outbox\OrganizationOutbox;
use stdClass;
use Throwable;
use UnexpectedValueException;

final class TemporaryAssignmentHandler
{
    private const MAX_CAPABILITIES = 100;

    private const MAX_REASON_LENGTH = 2000;

    public function __construct(
        private readonly OrganizationOutbox $outbox,
        private readonly BuildTemporaryAssignmentEvent $events,
        private readonly ValidateTemporaryAssignmentCapabilities $capabilities,
    ) {}

    /**
     * organization_unit_id is the sole authority scope. Position-derived,
     * hierarchical, facility, cluster, tag, and wildcard scopes are rejected.
     *
     * @param  array<string, mixed>  $input
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @return array{created: bool, temporary_assignment: array<string, mixed>}
     */
    public function create(
        string $temporaryAssignmentId,
        array $input,
        string $approvedByUserId,
        string $correlationId,
        array $idempotency,
    ): array {
        $temporaryAssignmentId = $this->identifier($temporaryAssignmentId);
        $approvedByUserId = $this->identifier($approvedByUserId);
        $correlationId = $this->identifier($correlationId);
        if (array_key_exists('position_id', $input)) {
            throw new InvalidArgumentException('temporary_assignment_position_scope_not_supported');
        }
        $personId = $this->identifier($input['person_id'] ?? null);
        $organizationUnitId = $this->identifier($input['organization_unit_id'] ?? null);
        $start = $this->timestamp($input['start_at'] ?? null);
        $end = $this->timestamp($input['end_at'] ?? null);
        $capabilityCodes = $this->capabilityCodes($input['capability_codes'] ?? null);
        $reason = $this->reason($input['reason'] ?? null);

        if ($end->lessThanOrEqualTo($start)) {
            throw new InvalidArgumentException('temporary_assignment_window_invalid');
        }
        if ($end->greaterThan($start->addDays(90))) {
            throw new InvalidArgumentException('temporary_assignment_window_too_long');
        }

        return DB::transaction(function () use (
            $temporaryAssignmentId,
            $personId,
            $organizationUnitId,
            $start,
            $end,
            $capabilityCodes,
            $reason,
            $approvedByUserId,
            $correlationId,
            $idempotency,
        ): array {
            $existing = $this->idempotencyQuery($idempotency)->first();
            if ($existing instanceof stdClass) {
                return $this->replay($existing, $idempotency['request_hash'], 'create');
            }

            $now = $this->now();
            if ($start->lessThan($now)) {
                throw new InvalidArgumentException('temporary_assignment_backdated');
            }

            $scope = $this->assertReferences($personId, $organizationUnitId);
            $serializedReplay = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
            if ($serializedReplay instanceof stdClass) {
                return $this->replay($serializedReplay, $idempotency['request_hash'], 'create');
            }
            $concurrent = $this->claimIdempotency($temporaryAssignmentId, $idempotency, 'create');
            if ($concurrent !== null) {
                return $concurrent;
            }
            $this->assertGovernedCapabilities($capabilityCodes);
            if ($this->overlaps($personId, $organizationUnitId, $capabilityCodes, $start, $end)) {
                throw new DomainException('temporary_assignment_capability_overlap');
            }

            $insertAt = $this->now();
            if ($start->lessThan($insertAt)) {
                throw new InvalidArgumentException('temporary_assignment_backdated');
            }

            DB::table('temporary_assignments')->insert([
                'id' => $temporaryAssignmentId,
                'person_id' => $personId,
                'organization_unit_id' => $scope['organization_unit_id'],
                'start_at' => $this->databaseTimestamp($start),
                'end_at' => $this->databaseTimestamp($end),
                'state' => $start->greaterThan($insertAt) ? 'pending' : 'active',
                'reason' => $reason,
                'approved_by_user_id' => $approvedByUserId,
                'revoked_at' => null,
                'revoked_by_user_id' => null,
                'revocation_reason' => null,
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('temporary_assignment_capabilities')->insert(array_map(
                static fn (string $capabilityCode): array => [
                    'temporary_assignment_id' => $temporaryAssignmentId,
                    'capability_code' => $capabilityCode,
                ],
                $capabilityCodes,
            ));

            $temporaryAssignment = $this->findRequired($temporaryAssignmentId);
            $this->storeReplay($idempotency, $temporaryAssignment);
            $this->outbox->insert($this->events->make(
                'com.cluster.organization.temporaryassignmentcreated.v1',
                $temporaryAssignment,
                $approvedByUserId,
                $scope['tenant_id'],
                $correlationId,
            ), $temporaryAssignmentId);

            return [
                'created' => true,
                'temporary_assignment' => $temporaryAssignment,
            ];
        });
    }

    /**
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @return array{changed: bool, temporary_assignment: array<string, mixed>}
     */
    public function revoke(
        string $temporaryAssignmentId,
        int $expectedVersion,
        string $reason,
        string $revokedByUserId,
        string $correlationId,
        array $idempotency,
    ): array {
        $temporaryAssignmentId = $this->identifier($temporaryAssignmentId);
        $revokedByUserId = $this->identifier($revokedByUserId);
        $correlationId = $this->identifier($correlationId);
        $revocationReason = $this->reason($reason);

        return DB::transaction(function () use (
            $temporaryAssignmentId,
            $expectedVersion,
            $revocationReason,
            $revokedByUserId,
            $correlationId,
            $idempotency,
        ): array {
            $existing = $this->idempotencyQuery($idempotency)->first();
            if ($existing instanceof stdClass) {
                return $this->replay($existing, $idempotency['request_hash'], 'revoke');
            }

            $row = DB::table('temporary_assignments')
                ->where('id', $temporaryAssignmentId)
                ->lockForUpdate()
                ->first();
            if (! $row instanceof stdClass) {
                throw new DomainException('temporary_assignment_not_found');
            }
            $serializedReplay = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
            if ($serializedReplay instanceof stdClass) {
                return $this->replay($serializedReplay, $idempotency['request_hash'], 'revoke');
            }
            $concurrent = $this->claimIdempotency($temporaryAssignmentId, $idempotency, 'revoke');
            if ($concurrent !== null) {
                return $concurrent;
            }
            if ((int) $row->lock_version !== $expectedVersion) {
                throw new DomainException('precondition_failed');
            }

            $state = $this->effectiveState($row, $this->now());
            if ($state === 'revoked') {
                throw new DomainException('temporary_assignment_already_revoked');
            }
            if ($state === 'expired') {
                throw new DomainException('temporary_assignment_expired');
            }

            $version = (int) $row->lock_version + 1;
            $updated = DB::table('temporary_assignments')
                ->where('id', $temporaryAssignmentId)
                ->where('lock_version', $expectedVersion)
                ->update([
                    'state' => 'revoked',
                    'revoked_at' => $this->databaseTimestamp($this->now()),
                    'revoked_by_user_id' => $revokedByUserId,
                    'revocation_reason' => $revocationReason,
                    'lock_version' => $version,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new DomainException('precondition_failed');
            }

            $temporaryAssignment = $this->findRequired($temporaryAssignmentId);
            $this->storeReplay($idempotency, $temporaryAssignment);
            $tenantId = $this->clusterIdForUnit((string) $row->organization_unit_id);
            $this->outbox->insert($this->events->make(
                'com.cluster.organization.temporaryassignmentrevoked.v1',
                $temporaryAssignment,
                $revokedByUserId,
                $tenantId,
                $correlationId,
            ), $temporaryAssignmentId);

            return [
                'changed' => true,
                'temporary_assignment' => $temporaryAssignment,
            ];
        });
    }

    /**
     * lock_version is the mutation precondition. representation_etag is a
     * deliberately weak cache validator because effective state is derived
     * from the clock at state_evaluated_at and may change without a write.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $temporaryAssignmentId): ?array
    {
        $row = DB::table('temporary_assignments')->where('id', $temporaryAssignmentId)->first();

        return $row instanceof stdClass ? $this->serialize($row) : null;
    }

    /** @return array{organization_unit_id: string, tenant_id: string} */
    private function assertReferences(string $personId, string $organizationUnitId): array
    {
        $person = DB::table('people')->where('id', $personId)->lockForUpdate()->first();
        if (! $person instanceof stdClass) {
            throw new DomainException('person_not_found');
        }
        if ($person->status !== 'active') {
            throw new DomainException('person_inactive');
        }

        $unit = DB::table('organization_units')
            ->where('id', $organizationUnitId)
            ->lockForUpdate()
            ->first();
        if (! $unit instanceof stdClass) {
            throw new DomainException('organization_unit_not_found');
        }
        if ($unit->status !== 'active') {
            throw new DomainException('organization_unit_inactive');
        }

        return [
            'organization_unit_id' => $organizationUnitId,
            'tenant_id' => (string) $unit->cluster_id,
        ];
    }

    /** @param list<string> $capabilityCodes */
    private function assertGovernedCapabilities(array $capabilityCodes): void
    {
        try {
            $allAreActive = $this->capabilities->allAreActive($capabilityCodes);
        } catch (Throwable) {
            throw new DomainException('temporary_assignment_capability_validation_unavailable');
        }
        if (! $allAreActive) {
            throw new DomainException('temporary_assignment_capability_not_active');
        }
    }

    private function clusterIdForUnit(string $organizationUnitId): string
    {
        $clusterId = DB::table('organization_units')
            ->where('id', $organizationUnitId)
            ->value('cluster_id');
        if (! is_string($clusterId) || $clusterId === '') {
            throw new DomainException('temporary_assignment_organization_unit_unavailable');
        }

        return $clusterId;
    }

    /** @param list<string> $capabilityCodes */
    private function overlaps(
        string $personId,
        string $organizationUnitId,
        array $capabilityCodes,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): bool {
        return DB::table('temporary_assignments as assignment')
            ->join(
                'temporary_assignment_capabilities as capability',
                'capability.temporary_assignment_id',
                '=',
                'assignment.id',
            )
            ->where('assignment.person_id', $personId)
            ->where('assignment.organization_unit_id', $organizationUnitId)
            ->where('assignment.state', '!=', 'revoked')
            ->where('assignment.start_at', '<', $this->databaseTimestamp($end))
            ->where('assignment.end_at', '>', $this->databaseTimestamp($start))
            ->whereIn('capability.capability_code', $capabilityCodes)
            ->lockForUpdate()
            ->first(['assignment.id']) instanceof stdClass;
    }

    /** @return array<string, mixed> */
    private function findRequired(string $temporaryAssignmentId): array
    {
        $temporaryAssignment = $this->find($temporaryAssignmentId);
        if ($temporaryAssignment === null) {
            throw new UnexpectedValueException('The temporary assignment write could not be read back.');
        }

        return $temporaryAssignment;
    }

    /** @return array<string, mixed> */
    private function serialize(stdClass $row): array
    {
        /** @var list<string> $capabilityCodes */
        $capabilityCodes = DB::table('temporary_assignment_capabilities')
            ->where('temporary_assignment_id', $row->id)
            ->orderBy('capability_code')
            ->pluck('capability_code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->values()
            ->all();

        $id = (string) $row->id;
        $lockVersion = (int) $row->lock_version;
        $stateEvaluatedAt = $this->now();
        $state = $this->effectiveState($row, $stateEvaluatedAt);

        return [
            'id' => $id,
            'person_id' => (string) $row->person_id,
            'organization_unit_id' => (string) $row->organization_unit_id,
            'capability_codes' => $capabilityCodes,
            'start_at' => $this->timestamp((string) $row->start_at)->format('Y-m-d\TH:i:s.v\Z'),
            'end_at' => $this->timestamp((string) $row->end_at)->format('Y-m-d\TH:i:s.v\Z'),
            'state' => $state,
            'state_evaluated_at' => $stateEvaluatedAt->format('Y-m-d\TH:i:s.v\Z'),
            // Deliberately weak: clock-derived state can change without a mutation.
            'representation_etag' => 'W/"temporary-assignment-'.$id.'-v'.$lockVersion.'-'.$state.'"',
            'reason' => (string) $row->reason,
            'approved_by_user_id' => (string) $row->approved_by_user_id,
            'revoked_at' => $row->revoked_at === null
                ? null
                : $this->timestamp((string) $row->revoked_at)->format('Y-m-d\TH:i:s.v\Z'),
            'revocation_reason' => $row->revocation_reason,
            'lock_version' => $lockVersion,
        ];
    }

    private function effectiveState(stdClass $row, CarbonImmutable $at): string
    {
        if ($row->state === 'revoked' || $row->state === 'expired') {
            return (string) $row->state;
        }
        if ($this->timestamp((string) $row->end_at)->lessThanOrEqualTo($at)) {
            return 'expired';
        }
        if ($this->timestamp((string) $row->start_at)->greaterThan($at)) {
            return 'pending';
        }

        return 'active';
    }

    private function identifier(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) !== 1) {
            throw new InvalidArgumentException('temporary_assignment_reference_invalid');
        }

        return $value;
    }

    private function timestamp(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('temporary_assignment_timestamp_invalid');
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw new InvalidArgumentException('temporary_assignment_timestamp_invalid');
        }
    }

    /** @return list<string> */
    private function capabilityCodes(mixed $value): array
    {
        if (! is_array($value) || $value === [] || count($value) > self::MAX_CAPABILITIES) {
            throw new InvalidArgumentException('temporary_assignment_capabilities_required');
        }

        $capabilityCodes = [];
        foreach ($value as $capabilityCode) {
            if (! is_string($capabilityCode)
                || mb_strlen($capabilityCode) > 96
                || preg_match('/\A[a-z][a-z0-9]*(?:[.:-][a-z0-9]+)*\z/', $capabilityCode) !== 1) {
                throw new InvalidArgumentException('temporary_assignment_capability_invalid');
            }
            if (isset($capabilityCodes[$capabilityCode])) {
                throw new InvalidArgumentException('temporary_assignment_capability_duplicate');
            }
            $capabilityCodes[$capabilityCode] = true;
        }

        $codes = array_keys($capabilityCodes);
        sort($codes);

        return $codes;
    }

    private function reason(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('temporary_assignment_reason_invalid');
        }

        $reason = trim($value);
        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new InvalidArgumentException('temporary_assignment_reason_too_long');
        }

        return $reason;
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->floorMillisecond();
    }

    private function databaseTimestamp(CarbonImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.v');
    }

    /** @param array{principal_id: string, operation: string, key_hash: string, request_hash: string} $idempotency */
    /** @return array{created: bool, temporary_assignment: array<string, mixed>}|array{changed: bool, temporary_assignment: array<string, mixed>}|null */
    private function claimIdempotency(string $temporaryAssignmentId, array $idempotency, string $action): ?array
    {
        $claimed = DB::table('organization_idempotency_keys')->insertOrIgnore([
            'principal_id' => $idempotency['principal_id'],
            'operation' => $idempotency['operation'],
            'idempotency_key_hash' => $idempotency['key_hash'],
            'request_hash' => $idempotency['request_hash'],
            'resource_type' => 'temporary_assignment',
            'resource_id' => $temporaryAssignmentId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($claimed) {
            return null;
        }

        $concurrent = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
        if (! $concurrent instanceof stdClass) {
            throw new UnexpectedValueException('The temporary assignment idempotency claim could not be resolved.');
        }

        return $this->replay($concurrent, $idempotency['request_hash'], $action);
    }

    /** @param array{principal_id: string, operation: string, key_hash: string, request_hash: string} $idempotency */
    private function idempotencyQuery(array $idempotency): mixed
    {
        return DB::table('organization_idempotency_keys')
            ->where('principal_id', $idempotency['principal_id'])
            ->where('operation', $idempotency['operation'])
            ->where('idempotency_key_hash', $idempotency['key_hash']);
    }

    /** @param array{principal_id: string, operation: string, key_hash: string, request_hash: string} $idempotency */
    /** @param array<string, mixed> $temporaryAssignment */
    private function storeReplay(array $idempotency, array $temporaryAssignment): void
    {
        $this->idempotencyQuery($idempotency)->update([
            'response_payload' => json_encode($temporaryAssignment, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{created: bool, temporary_assignment: array<string, mixed>}|array{changed: bool, temporary_assignment: array<string, mixed>}
     */
    private function replay(stdClass $key, string $requestHash, string $action): array
    {
        if ($key->resource_type !== 'temporary_assignment' || ! is_string($key->response_payload)) {
            throw new UnexpectedValueException('Stored temporary assignment idempotency state is incomplete.');
        }
        try {
            $temporaryAssignment = json_decode($key->response_payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException('Stored temporary assignment idempotency response is invalid.');
        }
        if (! is_array($temporaryAssignment)) {
            throw new UnexpectedValueException('Stored temporary assignment idempotency response is invalid.');
        }
        if (! is_string($key->request_hash) || ! hash_equals($key->request_hash, $requestHash)) {
            throw new TemporaryAssignmentIdempotencyConflict;
        }

        return $action === 'create'
            ? ['created' => false, 'temporary_assignment' => $temporaryAssignment]
            : ['changed' => false, 'temporary_assignment' => $temporaryAssignment];
    }
}
