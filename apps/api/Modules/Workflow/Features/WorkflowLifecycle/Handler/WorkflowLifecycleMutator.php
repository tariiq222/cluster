<?php

namespace Modules\Workflow\Features\WorkflowLifecycle\Handler;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Organization\Contracts\GetDefaultClusterId;
use Shared\Contracts\TransactionalOutbox;

/**
 * Owns the transactional writes and outbox emissions for the workflow
 * lifecycle. The HTTP controller must not own DB transactions or
 * Outbox writes per the module boundary rules.
 */
final class WorkflowLifecycleMutator
{
    public function __construct(
        private readonly TransactionalOutbox $outbox,
        private readonly GetDefaultClusterId $defaultClusterId,
    ) {}

    public function tenantClusterId(): ?string
    {
        return $this->defaultClusterId->resolve();
    }

    /**
     * @param  array<string, mixed>  $graph
     * @return array{ok: true, definition: array<string, mixed>, version: array<string, mixed>}|array{ok: false, conflict: string}
     */
    public function createDefinition(array $input, string $principalId, array $graph, string $requestHash, string $keyHash): array
    {
        try {
            $created = DB::transaction(function () use ($input, $principalId, $graph, $requestHash, $keyHash): array {
                $id = Str::uuid7()->toString();
                $versionId = Str::uuid7()->toString();
                $now = now();
                DB::table('workflow_definitions')->insert([
                    'id' => $id,
                    'code' => $input['code'],
                    'source_record_type' => $input['source_record_type'],
                    'created_by_user_id' => $principalId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('workflow_versions')->insert([
                    'id' => $versionId,
                    'workflow_definition_id' => $id,
                    'version_number' => 1,
                    'definition_state' => 'draft',
                    'graph_document' => json_encode($graph, JSON_THROW_ON_ERROR),
                    'graph_hash' => hash('sha256', json_encode($graph, JSON_THROW_ON_ERROR)),
                    'dsl_version' => '1',
                    'published_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('workflow_idempotency_keys')->insert([
                    'principal_id' => $principalId,
                    'operation' => 'createWorkflowDefinition',
                    'key_hash' => $keyHash,
                    'request_hash' => $requestHash,
                    'resource_id' => $id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->outbox->append(
                    Str::uuid7()->toString(),
                    $id,
                    'workflow.definition.created.v1',
                    ['workflow_definition_id' => $id, 'workflow_version_id' => $versionId],
                );

                return [
                    'definition' => (array) DB::table('workflow_definitions')->where('id', $id)->first(),
                    'version' => (array) DB::table('workflow_versions')->where('id', $versionId)->first(),
                ];
            });

            return ['ok' => true] + $created;
        } catch (\Throwable $e) {
            return ['ok' => false, 'conflict' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $graph
     * @param  array{operation: string, key_hash: string, request_hash: string, principal_id: string}|null  $idempotency
     * @return array{ok: true, version_id: string, version: array<string, mixed>}|array{ok: false, conflict: string}
     */
    public function createVersion(string $definitionId, string $principalId, array $graph, ?array $idempotency = null): array
    {
        try {
            $versionId = Str::uuid7()->toString();
            $now = now();
            $version = DB::transaction(function () use ($definitionId, &$versionId, $graph, $principalId, &$now, $idempotency): array {
                // Lock the parent definition row so concurrent version
                // allocations for the same definition observe sequential
                // max(version_number) values; the unique
                // (workflow_definition_id, version_number) constraint is the
                // final backstop in case the parent lock is missing.
                DB::table('workflow_definitions')->where('id', $definitionId)->lockForUpdate()->first();
                $versionNumber = (int) DB::table('workflow_versions')->where('workflow_definition_id', $definitionId)->max('version_number') + 1;
                DB::table('workflow_versions')->insert([
                    'id' => $versionId,
                    'workflow_definition_id' => $definitionId,
                    'version_number' => $versionNumber,
                    'definition_state' => 'draft',
                    'submitted_by_user_id' => $principalId,
                    'approval_status' => 'draft',
                    'graph_document' => json_encode($graph, JSON_THROW_ON_ERROR),
                    'graph_hash' => hash('sha256', json_encode($graph, JSON_THROW_ON_ERROR)),
                    'dsl_version' => '1',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                // State, outbox, and idempotency must commit or roll back together.
                $this->outbox->append(
                    Str::uuid7()->toString(),
                    $versionId,
                    'workflow.version.created.v1',
                    ['workflow_version_id' => $versionId, 'actor_user_id' => $principalId],
                );
                if ($idempotency !== null) {
                    $this->storeIdempotency($versionId, $idempotency, $now);
                }

                return (array) DB::table('workflow_versions')->where('id', $versionId)->first();
            });

            return ['ok' => true, 'version_id' => $versionId, 'version' => $version];
        } catch (\Throwable $e) {
            return ['ok' => false, 'conflict' => $e->getMessage()];
        }
    }

    /**
     * @param  array{operation: string, key_hash: string, request_hash: string, principal_id: string}|null  $idempotency
     * @return array{ok: true, version: array<string, mixed>}|array{ok: false, conflict: string}
     */
    public function publishVersion(string $versionId, ?array $idempotency = null): array
    {
        // State, outbox, and idempotency must commit or roll back together.
        try {
            return DB::transaction(function () use ($versionId, $idempotency): array {
                $updated = DB::table('workflow_versions')->where('id', $versionId)->where('definition_state', 'draft')->update([
                    'definition_state' => 'published',
                    'published_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($updated !== 1) {
                    throw new \RuntimeException('The workflow version cannot be published.');
                }
                $this->outbox->append(
                    Str::uuid7()->toString(),
                    $versionId,
                    'workflow.version.published.v1',
                    ['workflow_version_id' => $versionId],
                );
                $now = now();
                if ($idempotency !== null) {
                    $this->storeIdempotency($versionId, $idempotency, $now);
                }
                $version = (array) DB::table('workflow_versions')->where('id', $versionId)->first();

                return ['ok' => true, 'version' => $version];
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'conflict' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>|null  $instance
     * @param  array{operation: string, key_hash: string, request_hash: string, principal_id: string}|null  $idempotency
     * @return array{ok: true, step: array<string, mixed>}|array{ok: false, conflict: string}
     */
    public function recordStepDecision(array $step, ?array $instance, string $expectedVersion, string $newState, string $decision, ?string $reason, string $principalId, string $correlationId, ?array $idempotency = null): array
    {
        try {
            $now = now();
            $updatedStep = DB::transaction(function () use ($step, $instance, $expectedVersion, $newState, $now, $decision, $reason, $principalId, $correlationId, $idempotency): array {
                $stepUpdated = DB::table('workflow_step_instances')->where('id', $step['id'])->where('lock_version', $expectedVersion)->update([
                    'state' => $newState,
                    'completed_at' => in_array($newState, ['completed', 'rejected'], true) ? $now : null,
                    'lock_version' => ((int) $expectedVersion) + 1,
                    'updated_at' => $now,
                ]);
                if ($stepUpdated !== 1) {
                    throw new \RuntimeException('workflow_step_version_stale');
                }
                if ($newState === 'completed' && $instance !== null) {
                    $open = DB::table('workflow_step_instances')->where('workflow_instance_id', $instance['id'])->whereNotIn('state', ['completed', 'cancelled'])->where('id', '!=', $step['id'])->count();
                    if ($open === 0) {
                        DB::table('workflow_instances')->where('id', $instance['id'])->update([
                            'state' => 'completed',
                            'completed_at' => $now,
                            'lock_version' => ((int) ($instance['lock_version'] ?? 0)) + 1,
                            'updated_at' => $now,
                        ]);
                    }
                }
                DB::table('workflow_decisions')->insert([
                    'id' => Str::uuid7()->toString(),
                    'workflow_step_id' => (string) $step['id'],
                    'workflow_instance_id' => (string) $step['workflow_instance_id'],
                    'decision' => substr($decision, 0, 16),
                    'reason' => $reason,
                    'actor_user_id' => $principalId,
                    'correlation_id' => $correlationId,
                    'decided_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->outbox->append(Str::uuid7()->toString(), (string) $step['workflow_instance_id'], 'workflow.step.decision.recorded.v1', [
                    'workflow_step_id' => (string) $step['id'],
                    'decision' => $decision,
                    'reason' => $reason,
                    'actor_user_id' => $principalId,
                    'correlation_id' => $correlationId,
                ]);
                if ($idempotency !== null) {
                    $this->storeIdempotency((string) $step['id'], $idempotency, $now);
                }

                return (array) DB::table('workflow_step_instances')->where('id', $step['id'])->first();
            });

            return ['ok' => true, 'step' => $updatedStep];
        } catch (\Throwable $e) {
            return ['ok' => false, 'conflict' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array{operation: string, key_hash: string, request_hash: string, principal_id: string}|null  $idempotency
     * @return array{ok: true, step: array<string, mixed>}|array{ok: false, conflict: string}
     */
    public function actOnStep(array $step, string $expectedVersion, string $stepAction, ?string $targetUserId, string $reason, string $principalId, string $correlationId, ?array $idempotency = null): array
    {
        try {
            return DB::transaction(function () use ($step, $expectedVersion, $stepAction, $targetUserId, $reason, $principalId, $correlationId, $idempotency): array {
                $now = now();
                $updates = ['lock_version' => ((int) $expectedVersion) + 1, 'updated_at' => $now];
                if ($stepAction === 'reassign') {
                    $updates['assignee_user_id'] = $targetUserId;
                }
                $stepUpdated = DB::table('workflow_step_instances')->where('id', $step['id'])->where('lock_version', $expectedVersion)->update($updates);
                if ($stepUpdated !== 1) {
                    throw new \RuntimeException('workflow_step_version_stale');
                }
                $this->outbox->append(Str::uuid7()->toString(), (string) $step['workflow_instance_id'], 'workflow.step.'.$stepAction.'.v1', [
                    'workflow_step_id' => (string) $step['id'],
                    'target_user_id' => $targetUserId,
                    'reason' => $reason,
                    'actor_user_id' => $principalId,
                    'correlation_id' => $correlationId,
                ]);
                if ($idempotency !== null) {
                    $this->storeIdempotency((string) $step['id'], $idempotency, $now);
                }

                return [
                    'ok' => true,
                    'step' => (array) DB::table('workflow_step_instances')->where('id', $step['id'])->first(),
                ];
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'conflict' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $instance
     * @param  array{operation: string, key_hash: string, request_hash: string, principal_id: string}|null  $idempotency
     * @return array{ok: true, instance: array<string, mixed>}|array{ok: false, conflict: string}
     */
    public function cancelInstance(array $instance, string $expectedVersion, string $reason, string $principalId, string $correlationId, ?array $idempotency = null): array
    {
        try {
            $now = now();
            $cancelled = DB::transaction(function () use ($instance, $expectedVersion, $now, $reason, $principalId, $correlationId, $idempotency): array {
                $instanceUpdated = DB::table('workflow_instances')->where('id', $instance['id'])->where('lock_version', $expectedVersion)->update([
                    'state' => 'cancelled',
                    'completed_at' => $now,
                    'lock_version' => ((int) $expectedVersion) + 1,
                    'updated_at' => $now,
                ]);
                if ($instanceUpdated !== 1) {
                    throw new \RuntimeException('workflow_instance_version_stale');
                }
                DB::table('workflow_step_instances')->where('workflow_instance_id', $instance['id'])->whereNotIn('state', ['completed', 'cancelled'])->update([
                    'state' => 'cancelled',
                    'updated_at' => $now,
                ]);
                $this->outbox->append(Str::uuid7()->toString(), (string) $instance['id'], 'workflow.instance.cancelled.v1', [
                    'workflow_instance_id' => (string) $instance['id'],
                    'reason' => $reason,
                    'actor_user_id' => $principalId,
                    'correlation_id' => $correlationId,
                ]);
                if ($idempotency !== null) {
                    $this->storeIdempotency((string) $instance['id'], $idempotency, $now);
                }

                return (array) DB::table('workflow_instances')->where('id', $instance['id'])->first();
            });

            return ['ok' => true, 'instance' => $cancelled];
        } catch (\Throwable $e) {
            return ['ok' => false, 'conflict' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{match: bool, resource_id: string}|null
     */
    public function replay(string $principalId, string $operation, string $key, array $payload): ?array
    {
        $row = DB::table('workflow_idempotency_keys')->where([
            'principal_id' => $principalId,
            'operation' => $operation,
            'key_hash' => hash('sha256', $key),
        ])->first();
        if ($row === null) {
            return null;
        }

        return [
            'match' => $row->request_hash === hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'resource_id' => (string) $row->resource_id,
        ];
    }

    /**
     * Insert an idempotency record.
     *
     * MUST be called from inside an already-open `DB::transaction(...)`
     * closure so the row commits or rolls back with the state change that
     * produced it.
     *
     * @param  array{operation: string, key_hash: string, request_hash: string, principal_id: string}  $idempotency
     */
    private function storeIdempotency(string $resourceId, array $idempotency, DateTimeInterface $now): void
    {
        DB::table('workflow_idempotency_keys')->insert([
            'principal_id' => $idempotency['principal_id'],
            'operation' => $idempotency['operation'],
            'key_hash' => $idempotency['key_hash'],
            'request_hash' => $idempotency['request_hash'],
            'resource_id' => $resourceId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
