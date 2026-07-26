<?php

namespace Modules\Organization\Features\UpdateFacility\Handler;

use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Organization\Infrastructure\Outbox\OrganizationOutbox;
use stdClass;
use UnexpectedValueException;

final class UpdateFacilityHandler
{
    public function __construct(
        private readonly OrganizationOutbox $outbox,
    ) {}

    /** @return array<string, mixed>|null */
    public function find(string $facilityId): ?array
    {
        $row = DB::table('facilities')
            ->join('facility_types', 'facility_types.id', '=', 'facilities.facility_type_id')
            ->where('facilities.id', $facilityId)
            ->select('facilities.*', 'facility_types.code as type_code')
            ->first();

        return $row instanceof stdClass ? $this->serialize($row) : null;
    }

    /**
     * @param  array{name?: string, status?: string}  $changes
     * @param  Closure(array<string, mixed>, string): array<string, mixed>  $eventFactory
     * @return array<string, mixed>
     */
    public function update(string $facilityId, int $expectedVersion, array $changes, Closure $eventFactory): array
    {
        return DB::transaction(function () use ($facilityId, $expectedVersion, $changes, $eventFactory): array {
            $row = DB::table('facilities')->where('id', $facilityId)->lockForUpdate()->first();
            if (! $row instanceof stdClass) {
                throw new DomainException('facility_not_found');
            }
            if ((int) $row->lock_version !== $expectedVersion) {
                throw new DomainException('precondition_failed');
            }

            $name = $changes['name'] ?? $row->name_ar;
            $status = $changes['status'] ?? $row->status;
            if (! is_string($name) || ! is_string($status)) {
                throw new InvalidArgumentException('facility_change_invalid');
            }
            if ($row->status === 'archived' || ! $this->allowsTransition($row->status, $status)) {
                throw new DomainException('invalid_facility_transition');
            }
            if ($name === $row->name_ar && $status === $row->status) {
                throw new InvalidArgumentException('facility_unchanged');
            }

            $typeCode = DB::table('facility_types')->where('id', $row->facility_type_id)->value('code');
            if (! is_string($typeCode)) {
                throw new UnexpectedValueException('Facility type state is incomplete.');
            }

            $version = (int) $row->lock_version + 1;
            $updated = DB::table('facilities')
                ->where('id', $facilityId)
                ->where('lock_version', $expectedVersion)
                ->update([
                    'name_ar' => $name,
                    'status' => $status,
                    'lock_version' => $version,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new DomainException('precondition_failed');
            }
            $facility = [
                'id' => $row->id,
                'cluster_id' => $row->cluster_id,
                'type_code' => $typeCode,
                'code' => $row->code,
                'name_ar' => $name,
                'name_en' => $row->name_en,
                'status' => $status,
                'lock_version' => $version,
            ];
            $this->outbox->insert($eventFactory($facility, $row->status), $facilityId);

            return $facility;
        });
    }

    private function allowsTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return match ($from) {
            'active' => $to === 'inactive',
            'inactive' => in_array($to, ['active', 'archived'], true),
            default => false,
        };
    }

    /** @return array<string, mixed> */
    private function serialize(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'cluster_id' => $row->cluster_id,
            'type_code' => $row->type_code,
            'code' => $row->code,
            'name_ar' => $row->name_ar,
            'name_en' => $row->name_en,
            'status' => $row->status,
            'lock_version' => (int) $row->lock_version,
        ];
    }
}
