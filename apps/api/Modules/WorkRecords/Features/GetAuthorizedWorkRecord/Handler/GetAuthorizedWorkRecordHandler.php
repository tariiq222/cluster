<?php

namespace Modules\WorkRecords\Features\GetAuthorizedWorkRecord\Handler;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use stdClass;

final class GetAuthorizedWorkRecordHandler
{
    public function __construct(
        private readonly DecideAccess $access,
    ) {}

    /**
     * @param  array{user_id: string, facility_id: string}  $principal
     * @return array<string, mixed>|null
     */
    public function handle(array $principal, string $recordId): ?array
    {
        $row = DB::table('work_records')->where('id', $recordId)->first();
        if (! $row instanceof stdClass) {
            return null;
        }

        $decision = $this->access->decide(
            ['facility_id' => $principal['facility_id']],
            'work_record.read',
            new RecordFacts(
                ownerFacilityId: $row->owner_facility_id,
                resourceType: 'work_record',
                classification: $row->classification,
            ),
        );

        return $decision->isAllowed() ? $this->serialize($row) : null;
    }

    /** @return array<string, mixed> */
    private function serialize(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'record_number' => $row->record_number,
            'work_type_version_id' => $row->work_type_version_id,
            'owner' => [
                'facility_id' => $row->owner_facility_id,
                'user_id' => $row->creator_user_id,
            ],
            'status' => $row->status,
            'classification' => $row->classification,
            'payload' => json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR),
            'lock_version' => (int) $row->lock_version,
            'submitted_at' => $this->timestamp($row->submitted_at),
            'created_at' => $this->timestamp($row->created_at),
            'updated_at' => $this->timestamp($row->updated_at),
        ];
    }

    private function timestamp(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }
}
