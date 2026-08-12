<?php

declare(strict_types=1);

namespace Modules\WorkRecords\Application;

use Modules\Authorization\Contracts\RecordFacts;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use stdClass;

final class WorkRecordResourceFacts
{
    public function __construct(
        private readonly ResolveOrganizationScopeAncestry $ancestry,
    ) {}

    public function forRecord(stdClass|array $record, ?array $facilityClusterIds = null): RecordFacts
    {
        $facilityId = $this->recordFacilityId($record);
        $facts = $facilityClusterIds === null
            ? ($facilityId === null ? null : $this->ancestry->ancestry('facility', $facilityId))
            : ($facilityId === null || ! array_key_exists($facilityId, $facilityClusterIds)
                ? null
                : [
                    'cluster_id' => $facilityClusterIds[$facilityId],
                    'facility_id' => $facilityId,
                    'unit_id' => null,
                ]);

        return $this->facts(
            facilityId: $facilityId,
            ancestry: $facts,
            classification: $this->stringValue($record, 'classification') ?? 'internal',
            recordId: $this->stringValue($record, 'id'),
            createdByUserId: $this->recordCreatorUserId($record),
            lifecycleState: $this->stringValue($record, 'status'),
            fieldPolicyKey: $this->stringValue($record, 'field_policy_key'),
            workTypeVersionId: $this->stringValue($record, 'work_type_version_id'),
            lockVersion: $this->intValue($record, 'lock_version'),
        );
    }

    public function forFacility(
        string $facilityId,
        string $classification,
        ?string $recordId = null,
        ?string $createdByUserId = null,
        ?string $lifecycleState = null,
        ?string $fieldPolicyKey = null,
        ?string $workTypeVersionId = null,
        ?int $lockVersion = null,
    ): RecordFacts {
        return $this->facts(
            facilityId: $facilityId,
            ancestry: $this->ancestry->ancestry('facility', $facilityId),
            classification: $classification,
            recordId: $recordId,
            createdByUserId: $createdByUserId,
            lifecycleState: $lifecycleState,
            fieldPolicyKey: $fieldPolicyKey,
            workTypeVersionId: $workTypeVersionId,
            lockVersion: $lockVersion,
        );
    }

    /** @param array{cluster_id?: mixed, facility_id?: mixed, unit_id?: mixed}|null $ancestry */
    private function facts(
        ?string $facilityId,
        ?array $ancestry,
        string $classification,
        ?string $recordId,
        ?string $createdByUserId,
        ?string $lifecycleState,
        ?string $fieldPolicyKey,
        ?string $workTypeVersionId,
        ?int $lockVersion,
    ): RecordFacts {
        $clusterId = is_string($ancestry['cluster_id'] ?? null)
            ? $ancestry['cluster_id']
            : null;
        $resolvedFacilityId = $clusterId !== null
            && is_string($ancestry['facility_id'] ?? null)
            && ($facilityId === null || $ancestry['facility_id'] === $facilityId)
            ? $ancestry['facility_id']
            : null;

        return new RecordFacts(
            ownerFacilityId: $resolvedFacilityId,
            resourceType: 'work_record',
            classification: $classification,
            organizationUnitId: null,
            recordId: $recordId,
            sourceModule: 'work-records',
            clusterId: $clusterId,
            createdByUserId: $createdByUserId,
            lifecycleState: $lifecycleState,
            fieldPolicyKey: $fieldPolicyKey,
            workTypeVersionId: $workTypeVersionId,
            lockVersion: $lockVersion,
        );
    }

    private function recordFacilityId(stdClass|array $record): ?string
    {
        $facilityId = $this->stringValue($record, 'owner_facility_id');
        if ($facilityId !== null) {
            return $facilityId;
        }

        $owner = is_array($record) ? ($record['owner'] ?? null) : ($record->owner ?? null);
        if (is_array($owner) && is_string($owner['facility_id'] ?? null)) {
            return $owner['facility_id'];
        }
        if ($owner instanceof stdClass && is_string($owner->facility_id ?? null)) {
            return $owner->facility_id;
        }

        return null;
    }

    private function recordCreatorUserId(stdClass|array $record): ?string
    {
        $creatorUserId = $this->stringValue($record, 'creator_user_id');
        if ($creatorUserId !== null) {
            return $creatorUserId;
        }

        $owner = is_array($record) ? ($record['owner'] ?? null) : ($record->owner ?? null);
        if (is_array($owner) && is_string($owner['user_id'] ?? null)) {
            return $owner['user_id'];
        }
        if ($owner instanceof stdClass && is_string($owner->user_id ?? null)) {
            return $owner->user_id;
        }

        return null;
    }

    private function stringValue(stdClass|array $record, string $key): ?string
    {
        $value = is_array($record) ? ($record[$key] ?? null) : ($record->{$key} ?? null);

        return is_string($value) ? $value : null;
    }

    private function intValue(stdClass|array $record, string $key): ?int
    {
        $value = is_array($record) ? ($record[$key] ?? null) : ($record->{$key} ?? null);

        return is_int($value) || is_numeric($value) ? (int) $value : null;
    }
}
