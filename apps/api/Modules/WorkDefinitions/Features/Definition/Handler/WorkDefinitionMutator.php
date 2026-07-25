<?php

namespace Modules\WorkDefinitions\Features\Definition\Handler;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Organization\Contracts\GetDefaultClusterId;
use Shared\Contracts\TransactionalOutbox;

/**
 * Owns the transactional writes for the WorkDefinition lifecycle
 * (create, version, transition). The HTTP controller must not own DB
 * transactions or Outbox writes per the module boundary rules.
 */
final class WorkDefinitionMutator
{
    public function __construct(
        private readonly TransactionalOutbox $outbox,
        private readonly DecideAccess $access,
        private readonly GetDefaultClusterId $defaultClusterId,
    ) {}

    /**
     * @param array{code: string, name: string, description?: ?string, default_classification: string} $input
     * @param array{user_id: string} $principal
     * @return array{resource: array<string, mixed>, lock_version: int, conflict?: string}
     */
    public function create(array $input, array $principal, string $keyHash, string $hash, string $idempotencyOperation): array
    {
        try {
            $resource = DB::transaction(function () use ($input, $principal, $hash, $keyHash, $idempotencyOperation): array {
                $id = Str::uuid7()->toString();
                $now = now();
                DB::table('work_definitions')->insert([
                    'id' => $id,
                    'code' => $input['code'],
                    'name' => $input['name'],
                    'description' => $input['description'] ?? null,
                    'default_classification' => $input['default_classification'],
                    'created_by_user_id' => $principal['user_id'],
                    'status' => 'active',
                    'lock_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('work_definition_idempotency_keys')->insert([
                    'principal_id' => $principal['user_id'],
                    'operation' => $idempotencyOperation,
                    'key_hash' => $keyHash,
                    'request_hash' => $hash,
                    'resource_id' => $id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->outbox->append(Str::uuid7()->toString(), $id, 'work_definition.created.v1', ['work_definition_id' => $id]);

                return (array) DB::table('work_definitions')->where('id', $id)->first();
            });

            return ['resource' => $resource, 'lock_version' => 1];
        } catch (\Throwable $e) {
            return ['resource' => [], 'lock_version' => 0, 'conflict' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $schemaDocument
     * @param array{user_id: string} $principal
     * @return array{resource: array<string, mixed>, lock_version: int}
     */
    public function createVersion(string $definitionId, array $schemaDocument, string $fieldPolicyKey, ?string $changeSummary, array $principal, string $keyHash, string $hash, string $idempotencyOperation): array
    {
        $resource = DB::transaction(function () use ($definitionId, $schemaDocument, $fieldPolicyKey, $changeSummary, $principal, $hash, $keyHash, $idempotencyOperation): array {
            $id = Str::uuid7()->toString();
            $now = now();
            $n = (int) DB::table('work_definition_versions')->where('work_definition_id', $definitionId)->max('version_number') + 1;
            DB::table('work_definition_versions')->insert([
                'id' => $id,
                'work_definition_id' => $definitionId,
                'version_number' => $n,
                'status' => 'draft',
                'schema_document' => json_encode($schemaDocument, JSON_THROW_ON_ERROR),
                'field_policy_key' => $fieldPolicyKey,
                'schema_hash' => hash('sha256', json_encode($schemaDocument, JSON_THROW_ON_ERROR)),
                'change_summary' => $changeSummary,
                'created_by_user_id' => $principal['user_id'],
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('work_definition_idempotency_keys')->insert([
                'principal_id' => $principal['user_id'],
                'operation' => $idempotencyOperation,
                'key_hash' => $keyHash,
                'request_hash' => $hash,
                'resource_id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->outbox->append(Str::uuid7()->toString(), $id, 'work_definition.version.created.v1', ['version_id' => $id]);

            return (array) DB::table('work_definition_versions')->where('id', $id)->first();
        });

        return ['resource' => $resource, 'lock_version' => 1];
    }

    /**
     * @return array{ok: bool, conflict?: string}
     */
    public function transition(string $versionId, string $expectedVersion, string $action, string $target, ?string $publishedAt): array
    {
        try {
            $now = now();
            DB::transaction(function () use ($versionId, $expectedVersion, $target, $now, $publishedAt, $action): void {
                $updated = DB::table('work_definition_versions')->where('id', $versionId)->where('lock_version', $expectedVersion)->update([
                    'status' => $target,
                    'lock_version' => ((int) $expectedVersion) + 1,
                    'published_at' => $target === 'published' ? $now : $publishedAt,
                    'updated_at' => $now,
                ]);
                if ($updated !== 1) {
                    throw new \RuntimeException('stale');
                }
                $this->outbox->append(Str::uuid7()->toString(), $versionId, 'work_definition.version.'.$action.'.v1', ['version_id' => $versionId]);
            });

            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'conflict' => $e->getMessage()];
        }
    }

    /**
     * @param array{user_id: string, facility_id: ?string} $principal
     */
    public function gate(array $principal, string $capability, string $correlationId): bool
    {
        $clusterId = $this->defaultClusterId->resolve();

        return $this->access->decide(
            [
                'user_id' => $principal['user_id'],
                'facility_id' => $principal['facility_id'] ?? null,
                'organization_unit_ids' => array_filter([$principal['facility_id'] ?? null]),
                'correlation_id' => $correlationId,
            ],
            $capability,
            new RecordFacts(
                ownerFacilityId: $principal['facility_id'] ?? null,
                resourceType: 'work_definition',
                classification: 'internal',
                clusterId: is_string($clusterId) ? $clusterId : null,
            ),
        )->isAllowed();
    }
}
