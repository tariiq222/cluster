<?php

namespace Modules\WorkRecords\Features\GetAuthorizedWorkRecord\Handler;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\AccessProjection;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\WorkRecords\Application\WorkRecordResourceFacts;
use stdClass;

final class GetAuthorizedWorkRecordHandler
{
    public function __construct(
        private readonly DecideAccess $access,
        private readonly WorkRecordResourceFacts $factsBuilder,
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
            [
                'user_id' => $principal['user_id'],
                'facility_id' => $principal['facility_id'],
                'organization_unit_ids' => array_filter([$principal['facility_id']]),
            ],
            'work_record.read',
            $this->factsFor($row),
        );

        return $decision->isAllowed()
            ? $this->serialize($row, AccessProjection::fromDecision($decision))
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(stdClass $row, AccessProjection $projection): array
    {
        return $projection->compose([
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
        ], function (array $payload, array $fieldAccess): array {
            $wildcard = $fieldAccess['*'] ?? null;
            foreach ($payload as $field => $value) {
                $state = $fieldAccess[$field] ?? $wildcard;
                if ($state === 'hidden') {
                    unset($payload[$field]);
                } elseif ($state === 'masked') {
                    $payload[$field] = '***';
                }
            }

            return $payload;
        });
    }

    private function factsFor(stdClass $row): RecordFacts
    {
        return $this->factsBuilder->forRecord($row);
    }

    private function timestamp(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }
}
