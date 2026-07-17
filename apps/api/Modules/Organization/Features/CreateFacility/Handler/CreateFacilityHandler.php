<?php

namespace Modules\Organization\Features\CreateFacility\Handler;

use DateTimeImmutable;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Modules\Organization\Domain\Facility;
use stdClass;
use UnexpectedValueException;

final class CreateFacilityHandler
{
    /**
     * @param  array<string, mixed>  $cloudEvent
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @return array{created: bool, request_hash_matches: bool, facility: array<string, mixed>}
     */
    public function persist(Facility $facility, array $cloudEvent, array $idempotency): array
    {
        return DB::transaction(function () use ($facility, $cloudEvent, $idempotency): array {
            $existingKey = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
            if ($existingKey instanceof stdClass) {
                return $this->replayResult($existingKey, $idempotency['request_hash']);
            }

            $clusterExists = DB::table('clusters')->where('id', $facility->clusterId)->lockForUpdate()->exists();
            $type = DB::table('facility_types')->where('code', $facility->typeCode)->where('is_active', true)->first();
            if (! $clusterExists || ! $type instanceof stdClass) {
                throw new InvalidArgumentException('Facility parent or type is invalid.');
            }
            if (DB::table('facilities')->where('cluster_id', $facility->clusterId)->where('code', $facility->code)->exists()) {
                throw new DomainException('facility_already_exists');
            }

            $claimed = DB::table('organization_idempotency_keys')->insertOrIgnore([
                'principal_id' => $idempotency['principal_id'],
                'operation' => $idempotency['operation'],
                'idempotency_key_hash' => $idempotency['key_hash'],
                'request_hash' => $idempotency['request_hash'],
                'resource_type' => 'facility',
                'resource_id' => $facility->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if (! $claimed) {
                $concurrent = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
                if (! $concurrent instanceof stdClass) {
                    throw new UnexpectedValueException('The idempotency claim could not be resolved.');
                }

                return $this->replayResult($concurrent, $idempotency['request_hash']);
            }

            $data = $facility->toArray();
            DB::table('facilities')->insert([
                'id' => $data['id'],
                'cluster_id' => $data['cluster_id'],
                'facility_type_id' => $type->id,
                'code' => $data['code'],
                'name_ar' => $data['name_ar'],
                'name_en' => $data['name_en'],
                'status' => $data['status'],
                'lock_version' => $data['lock_version'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->insertOutbox($cloudEvent, $facility->id);

            return ['created' => true, 'request_hash_matches' => true, 'facility' => $data];
        });
    }

    /** @param array{principal_id: string, operation: string, key_hash: string, request_hash: string} $idempotency */
    public function findReplay(array $idempotency): ?array
    {
        $key = $this->idempotencyQuery($idempotency)->first();

        return $key instanceof stdClass ? $this->replayResult($key, $idempotency['request_hash']) : null;
    }

    /**
     * @param  array{user_id: string, facility_id: string}  $principal
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function list(array $principal, ?string $cursor, int $limit): array
    {
        $afterId = $cursor === null ? null : $this->decodeCursor($cursor, $principal, $limit);
        $query = DB::table('facilities')
            ->join('facility_types', 'facility_types.id', '=', 'facilities.facility_type_id')
            ->orderBy('facilities.id')
            ->select('facilities.*', 'facility_types.code as type_code');
        if ($afterId !== null) {
            $query->where('facilities.id', '>', $afterId);
        }
        $items = $query->limit($limit + 1)
            ->get()
            ->map(fn (stdClass $row): array => $this->serialize($row))
            ->all();
        $hasNextPage = count($items) > $limit;
        if ($hasNextPage) {
            array_pop($items);
        }

        return [
            'items' => $items,
            'next_cursor' => $hasNextPage
                ? $this->encodeCursor($items[array_key_last($items)]['id'], $principal, $limit)
                : null,
        ];
    }

    /** @param array{principal_id: string, operation: string, key_hash: string, request_hash: string} $idempotency */
    private function idempotencyQuery(array $idempotency): mixed
    {
        return DB::table('organization_idempotency_keys')
            ->where('principal_id', $idempotency['principal_id'])
            ->where('operation', $idempotency['operation'])
            ->where('idempotency_key_hash', $idempotency['key_hash']);
    }

    /** @return array{created: bool, request_hash_matches: bool, facility: array<string, mixed>} */
    private function replayResult(stdClass $key, string $requestHash): array
    {
        $row = DB::table('facilities')
            ->join('facility_types', 'facility_types.id', '=', 'facilities.facility_type_id')
            ->where('facilities.id', $key->resource_id)
            ->select('facilities.*', 'facility_types.code as type_code')
            ->first();
        if (! $row instanceof stdClass) {
            throw new UnexpectedValueException('Stored idempotency state is incomplete.');
        }

        return [
            'created' => false,
            'request_hash_matches' => is_string($key->request_hash) && hash_equals($key->request_hash, $requestHash),
            'facility' => $this->serialize($row),
        ];
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

    /** @param array<string, mixed> $cloudEvent */
    private function insertOutbox(array $cloudEvent, string $aggregateId): void
    {
        DB::table('outbox_events')->insert([
            'event_id' => $cloudEvent['id'],
            'aggregate_id' => $aggregateId,
            'event_type' => $cloudEvent['type'],
            'cloud_event' => json_encode($cloudEvent, JSON_THROW_ON_ERROR),
            'occurred_at' => (new DateTimeImmutable($cloudEvent['time']))->format('Y-m-d H:i:s'),
            'published_at' => null,
            'delivery_attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function encodeCursor(string $facilityId, array $principal, int $limit): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 1,
            'after_id' => $facilityId,
            'limit' => $limit,
            'principal_id' => $principal['user_id'],
            'facility_id' => $principal['facility_id'],
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function decodeCursor(string $cursor, array $principal, int $limit): string
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new InvalidArgumentException('The facility cursor is invalid.');
        }

        if (! is_array($payload)
            || array_keys($payload) !== ['version', 'after_id', 'limit', 'principal_id', 'facility_id']
            || $payload['version'] !== 1
            || $payload['limit'] !== $limit
            || ! is_string($payload['principal_id'])
            || ! hash_equals($principal['user_id'], $payload['principal_id'])
            || ! is_string($payload['facility_id'])
            || ! hash_equals($principal['facility_id'], $payload['facility_id'])
            || ! is_string($payload['after_id'])
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $payload['after_id']) !== 1) {
            throw new InvalidArgumentException('The facility cursor is invalid.');
        }

        return $payload['after_id'];
    }
}
