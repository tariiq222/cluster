<?php

namespace Modules\Organization\Features\CreateCluster\Handler;

use DomainException;
use Illuminate\Support\Facades\DB;
use JsonException;
use Modules\Organization\Domain\Cluster;
use Modules\Organization\Infrastructure\Outbox\OrganizationOutbox;
use stdClass;
use UnexpectedValueException;

final class CreateClusterHandler
{
    public function __construct(
        private readonly OrganizationOutbox $outbox,
    ) {}

    /**
     * @param  array<string, mixed>  $cloudEvent
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @return array{created: bool, request_hash_matches: bool, cluster: array<string, mixed>}
     */
    public function persist(Cluster $cluster, array $cloudEvent, array $idempotency): array
    {
        return DB::transaction(function () use ($cluster, $cloudEvent, $idempotency): array {
            $existingKey = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
            if ($existingKey instanceof stdClass) {
                return $this->replayResult($existingKey, $idempotency['request_hash']);
            }

            $claimed = DB::table('organization_idempotency_keys')->insertOrIgnore([
                'principal_id' => $idempotency['principal_id'],
                'operation' => $idempotency['operation'],
                'idempotency_key_hash' => $idempotency['key_hash'],
                'request_hash' => $idempotency['request_hash'],
                'resource_type' => 'cluster',
                'resource_id' => $cluster->id,
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

            if (DB::table('clusters')->lockForUpdate()->exists()) {
                throw new DomainException('cluster_already_exists');
            }

            $data = $cluster->toArray();
            DB::table('clusters')->insert([
                'id' => $data['id'],
                'singleton_key' => 1,
                'code' => $data['code'],
                'name_ar' => $data['name_ar'],
                'name_en' => $data['name_en'],
                'status' => $data['status'],
                'lock_version' => $data['lock_version'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->idempotencyQuery($idempotency)->update([
                'response_payload' => json_encode($data, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            $this->outbox->insert($cloudEvent, $cluster->id);

            return ['created' => true, 'request_hash_matches' => true, 'cluster' => $data];
        });
    }

    /** @param array{principal_id: string, operation: string, key_hash: string, request_hash: string} $idempotency */
    public function findReplay(array $idempotency): ?array
    {
        $key = $this->idempotencyQuery($idempotency)->first();

        return $key instanceof stdClass ? $this->replayResult($key, $idempotency['request_hash']) : null;
    }

    /** @return array<string, mixed>|null */
    public function find(): ?array
    {
        $row = DB::table('clusters')->first();

        return $row instanceof stdClass ? $this->serialize($row) : null;
    }

    /** @param array{principal_id: string, operation: string, key_hash: string, request_hash: string} $idempotency */
    private function idempotencyQuery(array $idempotency): mixed
    {
        return DB::table('organization_idempotency_keys')
            ->where('principal_id', $idempotency['principal_id'])
            ->where('operation', $idempotency['operation'])
            ->where('idempotency_key_hash', $idempotency['key_hash']);
    }

    /** @return array{created: bool, request_hash_matches: bool, cluster: array<string, mixed>} */
    private function replayResult(stdClass $key, string $requestHash): array
    {
        if (! is_string($key->response_payload)) {
            throw new UnexpectedValueException('Stored idempotency state is incomplete.');
        }
        try {
            $cluster = json_decode($key->response_payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException('Stored idempotency response is invalid.');
        }
        if (! is_array($cluster)) {
            throw new UnexpectedValueException('Stored idempotency response is invalid.');
        }

        return [
            'created' => false,
            'request_hash_matches' => is_string($key->request_hash) && hash_equals($key->request_hash, $requestHash),
            'cluster' => $cluster,
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'code' => $row->code,
            'name_ar' => $row->name_ar,
            'name_en' => $row->name_en,
            'status' => $row->status,
            'lock_version' => (int) $row->lock_version,
        ];
    }
}
