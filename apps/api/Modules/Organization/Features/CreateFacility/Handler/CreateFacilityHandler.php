<?php

namespace Modules\Organization\Features\CreateFacility\Handler;

use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Organization\Domain\Facility;
use Modules\Organization\Infrastructure\Outbox\OrganizationOutbox;
use Modules\Organization\Infrastructure\Persistence\EncryptedCursor;
use Modules\Organization\Infrastructure\Persistence\OrganizationIdempotencyStore;
use stdClass;
use UnexpectedValueException;

final class CreateFacilityHandler
{
    public function __construct(
        private readonly OrganizationOutbox $outbox,
        private readonly OrganizationIdempotencyStore $idempotency,
        private readonly EncryptedCursor $cursor,
    ) {}

    /**
     * @param  array<string, mixed>  $cloudEvent
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @return array{created: bool, request_hash_matches: bool, facility: array<string, mixed>}
     */
    public function persist(Facility $facility, array $cloudEvent, array $idempotency): array
    {
        return DB::transaction(function () use ($facility, $cloudEvent, $idempotency): array {
            $existingKey = $this->idempotency->query($idempotency)->lockForUpdate()->first();
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

            if (! $this->idempotency->claim($idempotency, 'facility', $facility->id)) {
                $concurrent = $this->idempotency->query($idempotency)->lockForUpdate()->first();
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
            $this->idempotency->storeResponse($idempotency, $data);
            $this->outbox->insert($cloudEvent, $facility->id);

            return ['created' => true, 'request_hash_matches' => true, 'facility' => $data];
        });
    }

    /** @param array{principal_id: string, operation: string, key_hash: string, request_hash: string} $idempotency */
    public function findReplay(array $idempotency): ?array
    {
        $key = $this->idempotency->query($idempotency)->first();

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

    /** @return array{created: bool, request_hash_matches: bool, facility: array<string, mixed>} */
    private function replayResult(stdClass $key, string $requestHash): array
    {
        $facility = $this->idempotency->decodeResponse($key, 'facility');

        return [
            'created' => false,
            'request_hash_matches' => $this->idempotency->hashMatches($key, $requestHash),
            'facility' => $facility,
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

    /** @param array{user_id: string, facility_id: string} $principal */
    private function encodeCursor(string $facilityId, array $principal, int $limit): string
    {
        return $this->cursor->encrypt([
            'version' => 1,
            'after_id' => $facilityId,
            'limit' => $limit,
            'principal_id' => $principal['user_id'],
            'facility_id' => $principal['facility_id'],
        ]);
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function decodeCursor(string $cursor, array $principal, int $limit): string
    {
        $payload = $this->cursor->tryDecrypt($cursor);
        if ($payload === null
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
