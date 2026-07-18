<?php

namespace Modules\Organization\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Organization\Domain\RelationshipCapability;
use Modules\Organization\Domain\SupervisoryRelationship;

final class SupervisoryRelationshipHttpGateway
{
    /** @return array{items:list<array<string,mixed>>,next_cursor:string|null} */
    public function list(?string $cursor, int $limit): array
    {
        $query = DB::table('supervisory_relationships')->orderBy('id');
        if ($cursor !== null) {
            $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
            if (! is_string($decoded) || $decoded === '') {
                throw new InvalidArgumentException('supervisory_relationship_cursor_invalid');
            }
            $query->where('id', '>', $decoded);
        }
        $rows = $query->limit($limit + 1)->get();
        $nextCursor = null;
        if ($rows->count() > $limit) {
            $last = $rows->pop();
            $nextCursor = rtrim(strtr(base64_encode((string) $last->id), '+/', '-_'), '=');
        }

        return [
            'items' => $rows->map(fn (object $row): array => $this->serialize($row))->values()->all(),
            'next_cursor' => $nextCursor,
        ];
    }

    /** @param array<string,mixed> $input */
    /** @return array<string,mixed> */
    public function create(array $input): array
    {
        $id = Str::uuid7()->toString();
        $from = $this->parseUtc((string) $input['start_at']);
        $until = $this->parseUtc((string) ($input['end_at'] ?? ''));
        $capabilityCodes = array_values($input['capability_codes']);
        $capabilities = [];
        foreach ($capabilityCodes as $code) {
            $moduleCode = explode('.', (string) $code, 2)[0];
            $capabilities[] = RelationshipCapability::create(Str::uuid7()->toString(), $id, $moduleCode, (string) $code);
        }
        $relationship = SupervisoryRelationship::create(
            $id,
            (string) $input['source_unit_id'],
            (string) $input['target_unit_id'],
            (string) $input['relationship_type'],
            $from,
            $until,
            $capabilities,
        );
        $now = now()->utc()->format('Y-m-d H:i:s.v');
        DB::table('supervisory_relationships')->insert([
            ...$relationship->toPersistence(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('relationship_capabilities')->insert(array_map(
            static fn (RelationshipCapability $capability): array => $capability->toPersistence(),
            $capabilities,
        ));

        return $this->serialize(DB::table('supervisory_relationships')->where('id', $id)->first());
    }

    /** @return array<string,mixed> */
    private function serialize(object $row): array
    {
        $capabilities = DB::table('relationship_capabilities')
            ->where('supervisory_relationship_id', $row->id)
            ->orderBy('id')
            ->get(['id', 'module_code', 'capability_code'])
            ->map(static fn (object $capability): array => [
                'id' => $capability->id,
                'module_code' => $capability->module_code,
                'capability_code' => $capability->capability_code,
            ])->all();

        return [
            'id' => $row->id,
            'resource_type' => 'supervisory_relationship',
            'source_unit_id' => $row->source_organization_unit_id,
            'target_unit_id' => $row->target_organization_unit_id,
            'source_organization_unit_id' => $row->source_organization_unit_id,
            'target_organization_unit_id' => $row->target_organization_unit_id,
            'relationship_type' => $row->relationship_type,
            'start_at' => $this->responseUtc((string) $row->valid_from),
            'end_at' => $this->responseUtc((string) $row->valid_until),
            'valid_from' => $this->responseUtc((string) $row->valid_from),
            'valid_until' => $this->responseUtc((string) $row->valid_until),
            'capabilities' => $capabilities,
            'lock_version' => 1,
        ];
    }

    private function parseUtc(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s.v\Z') !== $value) {
            throw new InvalidArgumentException('supervisory_relationship_timestamp_invalid');
        }

        return $date;
    }

    private function responseUtc(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v', substr($value, 0, 23), new DateTimeZone('UTC'));

        return $date === false ? $value : $date->format('Y-m-d\TH:i:s.v\Z');
    }
}
