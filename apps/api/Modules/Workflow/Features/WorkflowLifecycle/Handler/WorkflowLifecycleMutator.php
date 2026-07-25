<?php

namespace Modules\Workflow\Features\WorkflowLifecycle\Handler;

use Illuminate\Database\QueryException;
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
     * @return array{ok: true, version_id: string}|array{ok: false, conflict: string}
     */
    public function createVersion(string $definitionId, string $principalId, array $graph): array
    {
        try {
            $versionId = Str::uuid7()->toString();
            $now = now();
            DB::transaction(function () use ($definitionId, &$versionId, $graph, $principalId, &$now): void {
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
            });
            $this->outbox->append(
                Str::uuid7()->toString(),
                $versionId,
                'workflow.version.created.v1',
                ['workflow_version_id' => $versionId, 'actor_user_id' => $principalId],
            );

            return ['ok' => true, 'version_id' => $versionId];
        } catch (\Throwable $e) {
            return ['ok' => false, 'conflict' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: true, version: array<string, mixed>}|array{ok: false, conflict: string}
     */
    public function publishVersion(string $versionId): array
    {
        $updated = DB::table('workflow_versions')->where('id', $versionId)->where('definition_state', 'draft')->update([
            'definition_state' => 'published',
            'published_at' => now(),
            'updated_at' => now(),
        ]);
        if ($updated !== 1) {
            return ['ok' => false, 'conflict' => 'The workflow version cannot be published.'];
        }
        $this->outbox->append(
            Str::uuid7()->toString(),
            $versionId,
            'workflow.version.published.v1',
            ['workflow_version_id' => $versionId],
        );
        $version = (array) DB::table('workflow_versions')->where('id', $versionId)->first();

        return ['ok' => true, 'version' => $version];
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>|null  $instance
     * @return array{ok: true}|array{ok: false, conflict: string}
     */
    public function recordStepDecision(array $step, ?array $instance, string $expectedVersion, string $newState, string $decision, ?string $reason, string $principalId, string $correlationId): array
    {
        try {
            $now = now();
            DB::transaction(function () use ($step, $instance, $expectedVersion, $newState, $now, $decision, $reason, $principalId, $correlationId): void {
                DB::table('workflow_step_instances')->where('id', $step['id'])->where('lock_version', $expectedVersion)->update([
                    'state' => $newState,
                    'completed_at' => in_array($newState, ['completed', 'rejected'], true) ? $now : null,
                    'lock_version' => ((int) $expectedVersion) + 1,
                    'updated_at' => $now,
                ]);
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
                $this->outbox->append(Str::uuid7()->toString(), (string) $step['workflow_instance_id'], 'workflow.step.decision.recorded.v1', [
                    'workflow_step_id' => (string) $step['id'],
                    'decision' => $decision,
                    'reason' => $reason,
                    'actor_user_id' => $principalId,
                    'correlation_id' => $correlationId,
                ]);
            });

            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'conflict' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array{ok: true}|array{ok: false, conflict: string}
     */
    public function actOnStep(array $step, string $expectedVersion, string $stepAction, ?string $targetUserId, string $reason, string $principalId, string $correlationId): array
    {
        try {
            $now = now();
            $updates = ['lock_version' => ((int) $expectedVersion) + 1, 'updated_at' => $now];
            if ($stepAction === 'reassign') {
                $updates['assignee_user_id'] = $targetUserId;
            }
            DB::table('workflow_step_instances')->where('id', $step['id'])->where('lock_version', $expectedVersion)->update($updates);
            $this->outbox->append(Str::uuid7()->toString(), (string) $step['workflow_instance_id'], 'workflow.step.'.$stepAction.'.v1', [
                'workflow_step_id' => (string) $step['id'],
                'target_user_id' => $targetUserId,
                'reason' => $reason,
                'actor_user_id' => $principalId,
                'correlation_id' => $correlationId,
            ]);

            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'conflict' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $instance
     * @return array{ok: true}|array{ok: false, conflict: string}
     */
    public function cancelInstance(array $instance, string $expectedVersion, string $reason, string $principalId, string $correlationId): array
    {
        try {
            $now = now();
            DB::transaction(function () use ($instance, $expectedVersion, $now, $reason, $principalId, $correlationId): void {
                DB::table('workflow_instances')->where('id', $instance['id'])->where('lock_version', $expectedVersion)->update([
                    'state' => 'cancelled',
                    'completed_at' => $now,
                    'lock_version' => ((int) $expectedVersion) + 1,
                    'updated_at' => $now,
                ]);
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
            });

            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'conflict' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function remember(string $principalId, string $operation, string $key, array $payload, string $resourceId): void
    {
        try {
            DB::table('workflow_idempotency_keys')->insert([
                'principal_id' => $principalId,
                'operation' => $operation,
                'key_hash' => hash('sha256', $key),
                'request_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                'resource_id' => $resourceId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            // Replay already recorded by a concurrent writer; the response stays deterministic.
        }
    }

    /**
     * @param  array<string, mixed>  $payload
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

        return ['match' => $row->request_hash === hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))];
    }
}
