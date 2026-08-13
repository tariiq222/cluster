<?php

declare(strict_types=1);

namespace Modules\Reporting\Application;

use Modules\Authorization\Contracts\RecordFacts;

/**
 * Rebuilds authorization facts from the projection's persisted source facts.
 * `scope_id` is interpreted only together with `data.scope_type`; legacy rows
 * without a type retain the historical facility-scope interpretation.
 */
final class ReportingAuthorizationFacts
{
    /** @param array<string, mixed> $item */
    public static function forExportItem(array $item): RecordFacts
    {
        $data = is_array($item['data'] ?? null) ? $item['data'] : [];

        return self::forSource(
            self::string($item['source_type'] ?? null) ?? 'report_record',
            self::string($item['source_id'] ?? null),
            self::string($item['scope_id'] ?? null),
            self::string($item['classification'] ?? null) ?? 'internal',
            $data,
            self::string($item['source_module'] ?? null),
        );
    }

    public static function forProjectionRow(object $row): RecordFacts
    {
        $data = json_decode((string) ($row->safe_data ?? ''), true);

        return self::forSource(
            (string) $row->source_type,
            (string) $row->source_id,
            self::string($row->scope_id ?? null),
            (string) $row->classification,
            is_array($data) ? $data : [],
            self::string($row->source_module ?? null),
        );
    }

    public static function forRequestedReport(string $reportId, ?string $scopeId, ?string $clusterId = null): RecordFacts
    {
        return new RecordFacts(
            ownerFacilityId: self::string($scopeId),
            resourceType: 'report_definition',
            classification: 'internal',
            recordId: $reportId,
            clusterId: self::string($clusterId),
        );
    }

    /** @param array<string, mixed> $data */
    private static function forSource(
        string $resourceType,
        ?string $recordId,
        ?string $scopeId,
        string $classification,
        array $data,
        ?string $sourceModule,
    ): RecordFacts {
        $scopeType = self::string($data['scope_type'] ?? null);
        if (! in_array($scopeType, ['cluster', 'facility', 'unit', 'record_set'], true)) {
            $scopeType = $scopeId === null ? null : 'facility';
        }

        $clusterId = self::string($data['cluster_id'] ?? null);
        $facilityId = self::string($data['owner_facility_id'] ?? $data['facility_id'] ?? null);
        $organizationUnitId = self::string($data['organization_unit_id'] ?? null);

        if ($scopeType === 'cluster') {
            $clusterId = $scopeId;
            $facilityId = null;
            $organizationUnitId = null;
        } elseif ($scopeType === 'facility') {
            $facilityId = $scopeId;
            $organizationUnitId = null;
        } elseif ($scopeType === 'unit') {
            $organizationUnitId = $scopeId;
        }

        return new RecordFacts(
            ownerFacilityId: $facilityId,
            resourceType: $resourceType,
            classification: $classification,
            organizationUnitId: $organizationUnitId,
            recordId: $recordId,
            sourceModule: $sourceModule,
            clusterId: $clusterId,
        );
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
