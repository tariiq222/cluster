<?php

namespace Modules\Organization\Features\CreateCluster\Handler;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Organization\Domain\Cluster;
use Modules\Organization\Infrastructure\Outbox\OrganizationOutbox;
use Modules\Organization\Infrastructure\Persistence\OrganizationIdempotencyStore;
use stdClass;
use UnexpectedValueException;

final class CreateClusterHandler
{
    public function __construct(
        private readonly OrganizationOutbox $outbox,
        private readonly OrganizationIdempotencyStore $idempotency,
    ) {}

    /**
     * @param  array<string, mixed>  $cloudEvent
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @return array{created: bool, request_hash_matches: bool, cluster: array<string, mixed>}
     */
    public function persist(Cluster $cluster, array $cloudEvent, array $idempotency): array
    {
        return DB::transaction(function () use ($cluster, $cloudEvent, $idempotency): array {
            $existingKey = $this->idempotency->query($idempotency)->lockForUpdate()->first();
            if ($existingKey instanceof stdClass) {
                return $this->replayResult($existingKey, $idempotency['request_hash']);
            }

            if (DB::table('clusters')->lockForUpdate()->exists()) {
                throw new DomainException('cluster_already_exists');
            }

            $data = $cluster->toArray();
            if (! $this->idempotency->claim($idempotency, 'cluster', $cluster->id)) {
                $concurrent = $this->idempotency->query($idempotency)->lockForUpdate()->first();
                if (! $concurrent instanceof stdClass) {
                    throw new UnexpectedValueException('The idempotency claim could not be resolved.');
                }

                return $this->replayResult($concurrent, $idempotency['request_hash']);
            }

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
            $this->idempotency->storeResponse($idempotency, $data);
            $this->outbox->insert($cloudEvent, $cluster->id);

            return ['created' => true, 'request_hash_matches' => true, 'cluster' => $data];
        });
    }

    /** @param array{principal_id: string, operation: string, key_hash: string, request_hash: string} $idempotency */
    public function findReplay(array $idempotency): ?array
    {
        $key = $this->idempotency->query($idempotency)->first();

        return $key instanceof stdClass ? $this->replayResult($key, $idempotency['request_hash']) : null;
    }

    /** @return array<string, mixed>|null */
    public function find(): ?array
    {
        $row = DB::table('clusters')->first();

        return $row instanceof stdClass ? $this->serialize($row) : null;
    }

    /** @return array{created: bool, request_hash_matches: bool, cluster: array<string, mixed>} */
    private function replayResult(stdClass $key, string $requestHash): array
    {
        $cluster = $this->idempotency->decodeResponse($key, 'cluster');

        return [
            'created' => false,
            'request_hash_matches' => $this->idempotency->hashMatches($key, $requestHash),
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
