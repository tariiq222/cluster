<?php

namespace App\Http\Controllers\Api;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Workflow\Features\StartWorkflow\Handler\StartWorkflowHandler;
use Shared\Contracts\TransactionalOutbox;

final class WorkflowController
{
    use HttpSupport;

    public function __construct(private readonly ResolveDevelopmentFixturePrincipal $resolver, private readonly TransactionalOutbox $outbox, private readonly StartWorkflowHandler $starter, private readonly DecideAccess $access) {}

    public function definitions(Request $request): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        } $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }
        if (($deny = $this->denyUnlessAllowed($p, $request->isMethod('get') ? 'workflow.read' : 'workflow.manage', null, null, $c)) !== null) {
            return $deny;
        }
        if ($request->isMethod('get')) {
            return response()->json(['items' => DB::table('workflow_definitions')->orderBy('created_at')->get()->map(fn ($r) => (array) $r), 'next_cursor' => null])->header('X-Correlation-ID', $c);
        }
        $key = $this->commandHeaders($request);
        if ($key === '') {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $c);
        } $v = $request->json()->all();
        if (! is_string($v['code'] ?? null) || ! is_string($v['name'] ?? null) || ! is_string($v['source_record_type'] ?? null)) {
            return $this->problem(422, 'invalid-workflow-definition', 'The request body is invalid.', $c);
        }
        $graph = ['nodes' => [['key' => 'start', 'type' => 'start'], ['key' => 'task', 'type' => 'task', 'configuration' => ['title' => $v['name']]], ['key' => 'end', 'type' => 'end']], 'transitions' => [['from' => 'start', 'to' => 'task'], ['from' => 'task', 'to' => 'end']], 'decision_policy' => ['default' => 'owner']];
        $requestHash = hash('sha256', json_encode($v, JSON_THROW_ON_ERROR));
        $keyHash = hash('sha256', $key);
        $existing = DB::table('workflow_idempotency_keys')->where(['principal_id' => $p['user_id'], 'operation' => 'createWorkflowDefinition', 'key_hash' => $keyHash])->first();
        if ($existing !== null) {
            return $existing->request_hash === $requestHash ? $this->showDefinition((string) $existing->resource_id, $c, 201) : $this->problem(409, 'idempotency-conflict', 'Idempotency-Key was already used for a different request.', $c);
        }
        try {
            $row = DB::transaction(function () use ($v, $p, $graph, $requestHash, $keyHash): array {
                $id = Str::uuid7()->toString();
                $versionId = Str::uuid7()->toString();
                $now = now();
                DB::table('workflow_definitions')->insert(['id' => $id, 'code' => $v['code'], 'source_record_type' => $v['source_record_type'], 'created_by_user_id' => $p['user_id'], 'created_at' => $now, 'updated_at' => $now]);
                DB::table('workflow_versions')->insert(['id' => $versionId, 'workflow_definition_id' => $id, 'version_number' => 1, 'definition_state' => 'draft', 'graph_document' => json_encode($graph, JSON_THROW_ON_ERROR), 'graph_hash' => hash('sha256', json_encode($graph, JSON_THROW_ON_ERROR)), 'dsl_version' => '1', 'published_at' => null, 'created_at' => $now, 'updated_at' => $now]);
                DB::table('workflow_idempotency_keys')->insert(['principal_id' => $p['user_id'], 'operation' => 'createWorkflowDefinition', 'key_hash' => $keyHash, 'request_hash' => $requestHash, 'resource_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
                $this->outbox->append(Str::uuid7()->toString(), $id, 'workflow.definition.created.v1', ['workflow_definition_id' => $id, 'workflow_version_id' => $versionId]);

                return ['definition' => (array) DB::table('workflow_definitions')->where('id', $id)->first(), 'version' => (array) DB::table('workflow_versions')->where('id', $versionId)->first()];
            });

            return $this->response($row, 201, $c, 1);
        } catch (\Throwable $e) {
            return $this->problem(409, 'workflow-definition-conflict', $e->getMessage(), $c);
        }
    }

    public function versions(Request $request, string $definitionId): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        } if ($this->principal($request, $this->resolver) === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        } if (! DB::table('workflow_definitions')->where('id', $definitionId)->exists()) {
            return $this->problem(404, 'resource-not-found', 'The workflow definition is not available.', $c);
        }
        if ($request->isMethod('get')) {
            return response()->json(['items' => DB::table('workflow_versions')->where('workflow_definition_id', $definitionId)->orderBy('version_number')->get()->map(fn ($r) => $this->decode((array) $r)), 'next_cursor' => null])->header('X-Correlation-ID', $c);
        }
        $key = $this->commandHeaders($request);
        if ($key === '') {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $c);
        } $v = $request->json()->all();
        if (! is_array($v['nodes'] ?? null) || ! is_array($v['transitions'] ?? null)) {
            return $this->problem(422, 'invalid-workflow-version', 'The request body is invalid.', $c);
        } $p = $this->principal($request, $this->resolver);
        $graph = ['nodes' => $v['nodes'], 'transitions' => $v['transitions'], 'decision_policy' => $v['decision_policy'] ?? []];
        $versionId = Str::uuid7()->toString();
        $now = now();
        DB::table('workflow_versions')->insert(['id' => $versionId, 'workflow_definition_id' => $definitionId, 'version_number' => (int) DB::table('workflow_versions')->where('workflow_definition_id', $definitionId)->max('version_number') + 1, 'definition_state' => 'draft', 'submitted_by_user_id' => $p['user_id'], 'approval_status' => 'draft', 'graph_document' => json_encode($graph, JSON_THROW_ON_ERROR), 'graph_hash' => hash('sha256', json_encode($graph, JSON_THROW_ON_ERROR)), 'dsl_version' => '1', 'created_at' => $now, 'updated_at' => $now]);
        $this->outbox->append(Str::uuid7()->toString(), $versionId, 'workflow.version.created.v1', ['workflow_version_id' => $versionId, 'actor_user_id' => $p['user_id']]);

        return $this->response($this->decode((array) DB::table('workflow_versions')->where('id', $versionId)->first()), 201, $c, 1);
    }

    public function publish(Request $request, string $versionId, string $action): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        } $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        } $key = $this->commandHeaders($request);
        if ($key === '') {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $c);
        } $row = DB::table('workflow_versions')->where('id', $versionId)->first();
        if ($row === null) {
            return $this->problem(404, 'resource-not-found', 'The workflow version is not available.', $c);
        }
        if (is_string($row->submitted_by_user_id ?? null) && $row->submitted_by_user_id !== '' && $row->submitted_by_user_id === $p['user_id']) {
            return $this->problem(403, 'self-approval-forbidden', 'The submitter cannot approve their own workflow version.', $c);
        }
        $definition = DB::table('workflow_definitions')->where('id', $row->workflow_definition_id)->first();
        if ($definition !== null && (bool) ($definition->is_system ?? false)) {
            return $this->problem(403, 'system-path-readonly', 'System workflows cannot be modified.', $c);
        }
        if ($action !== 'publish') {
            return $this->problem(409, 'invalid-lifecycle-transition', 'Only publish is available in this vertical.', $c);
        } if ($row->definition_state === 'published') {
            return $this->response($this->decode((array) $row), 200, $c, 1);
        } $updated = DB::table('workflow_versions')->where('id', $versionId)->where('definition_state', 'draft')->update(['definition_state' => 'published', 'published_at' => now(), 'updated_at' => now()]);
        if ($updated !== 1) {
            return $this->problem(409, 'invalid-lifecycle-transition', 'The workflow version cannot be published.', $c);
        } $this->outbox->append(Str::uuid7()->toString(), $versionId, 'workflow.version.published.v1', ['workflow_version_id' => $versionId]);

        return $this->response($this->decode((array) DB::table('workflow_versions')->where('id', $versionId)->first()), 200, $c, 1);
    }

    public function instances(Request $request): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        } $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }
        if (($deny = $this->denyUnlessAllowed($p, $request->isMethod('get') ? 'workflow.read' : 'workflow.manage', null, null, $c)) !== null) {
            return $deny;
        }
        if ($request->isMethod('get')) {
            return response()->json(['items' => DB::table('workflow_instances')->where('started_by_user_id', $p['user_id'])->orderBy('created_at')->get()->map(fn ($r) => (array) $r), 'next_cursor' => null])->header('X-Correlation-ID', $c);
        } $key = $this->commandHeaders($request);
        if ($key === '') {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $c);
        } $v = $request->json()->all();
        foreach (['workflow_version_id', 'source_module', 'record_type', 'record_id'] as $field) {
            if (! is_string($v[$field] ?? null)) {
                return $this->problem(422, 'invalid-workflow-start', 'The request body is invalid.', $c);
            }
        } try {
            $instance = $this->starter->start($v['workflow_version_id'], $v['source_module'], $v['record_type'], $v['record_id'], $p['user_id']);

            return $this->response($instance, 201, $c, (int) ($instance['lock_version'] ?? 1));
        } catch (\Throwable $e) {
            return $this->problem(409, 'workflow-start-failed', $e->getMessage(), $c);
        }
    }

    public function showInstance(Request $request, string $instanceId): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        } if ($this->principal($request, $this->resolver) === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        } $r = DB::table('workflow_instances')->where('id', $instanceId)->first();

        return $r === null ? $this->problem(404, 'resource-not-found', 'The workflow instance is not available.', $c) : $this->response(['instance' => (array) $r, 'steps' => DB::table('workflow_step_instances')->where('workflow_instance_id', $instanceId)->get()->map(fn ($s) => (array) $s)], 200, $c, (int) $r->lock_version);
    }

    public function decideStep(Request $request, string $stepId): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }
        $key = $this->commandHeaders($request);
        if ($key === '') {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $c);
        }
        $v = $request->json()->all();
        if (! in_array($v['decision'] ?? null, ['approve', 'reject', 'return', 'accept', 'decline'], true)) {
            return $this->problem(422, 'invalid-workflow-decision', 'The request body is invalid.', $c);
        }
        $step = DB::table('workflow_step_instances')->where('id', $stepId)->first();
        if ($step === null) {
            return $this->problem(404, 'resource-not-found', 'The workflow step is not available.', $c);
        }
        $instance = DB::table('workflow_instances')->where('id', $step->workflow_instance_id)->first();
        if (($deny = $this->denyUnlessAllowed($p, 'workflow.decide', (string) $step->id, (string) $step->state, $c)) !== null) {
            return $deny;
        }
        if (($deny = $this->denyUnlessAssignee($step, $p, $c)) !== null) {
            return $deny;
        }
        $expected = $this->versionFromMatch($request);
        if ($expected === null || $expected !== (int) $step->lock_version) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        }
        if (! in_array($step->state, ['active', 'waiting'], true)) {
            return $this->problem(409, 'workflow-step-invalid-state', 'The workflow step is not in a state for this action.', $c);
        }
        $replay = $this->replay($p['user_id'], 'recordWorkflowDecision', $key, $v);
        if ($replay !== null) {
            return $replay['match'] ? $this->response($this->stepPayload((string) $step->id), 201, $c) : $this->problem(409, 'idempotency-conflict', 'Idempotency-Key was already used for a different request.', $c);
        }

        $newState = in_array($v['decision'], ['approve', 'accept'], true) ? 'completed' : ($v['decision'] === 'return' ? 'returned' : 'rejected');
        $now = now();
        DB::transaction(function () use ($step, $instance, $newState, $expected, $now, $v, $p, $c): void {
            DB::table('workflow_step_instances')->where('id', $step->id)->where('lock_version', $expected)->update([
                'state' => $newState,
                'completed_at' => in_array($newState, ['completed', 'rejected'], true) ? $now : null,
                'lock_version' => $expected + 1,
                'updated_at' => $now,
            ]);
            if ($newState === 'completed' && $instance !== null) {
                $open = DB::table('workflow_step_instances')->where('workflow_instance_id', $instance->id)->whereNotIn('state', ['completed', 'cancelled'])->where('id', '!=', $step->id)->count();
                if ($open === 0) {
                    DB::table('workflow_instances')->where('id', $instance->id)->update(['state' => 'completed', 'completed_at' => $now, 'lock_version' => ((int) $instance->lock_version) + 1, 'updated_at' => $now]);
                }
            }
            $this->outbox->append(Str::uuid7()->toString(), (string) $step->workflow_instance_id, 'workflow.step.decision.recorded.v1', [
                'workflow_step_id' => (string) $step->id,
                'decision' => $v['decision'],
                'reason' => is_string($v['reason'] ?? null) ? $v['reason'] : null,
                'actor_user_id' => $p['user_id'],
                'correlation_id' => $c,
            ]);
        });
        $this->remember($p['user_id'], 'recordWorkflowDecision', $key, $v, (string) $step->id);

        return $this->response($this->stepPayload((string) $step->id, ['decision' => $v['decision']]), 201, $c);
    }

    public function actOnStep(Request $request, string $stepId, string $stepAction): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }
        $key = $this->commandHeaders($request);
        if ($key === '') {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $c);
        }
        $v = $request->json()->all();
        if (! is_string($v['reason'] ?? null) || trim($v['reason']) === '' || mb_strlen($v['reason']) > 2000) {
            return $this->problem(422, 'invalid-workflow-step-action', 'The request body is invalid.', $c);
        }
        $targetUserId = $v['target_user_id'] ?? null;
        if ($stepAction === 'reassign' && (! is_string($targetUserId) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $targetUserId) !== 1)) {
            return $this->problem(422, 'invalid-workflow-step-action', 'The request body is invalid.', $c);
        }
        $step = DB::table('workflow_step_instances')->where('id', $stepId)->first();
        if ($step === null) {
            return $this->problem(404, 'resource-not-found', 'The workflow step is not available.', $c);
        }
        $capability = $stepAction === 'reassign' ? 'workflow.reassign' : 'workflow.escalate';
        if (($deny = $this->denyUnlessAllowed($p, $capability, (string) $step->id, (string) $step->state, $c)) !== null) {
            return $deny;
        }
        $expected = $this->versionFromMatch($request);
        if ($expected === null || $expected !== (int) $step->lock_version) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        }
        if (! in_array($step->state, ['active', 'waiting'], true)) {
            return $this->problem(409, 'workflow-step-invalid-state', 'The workflow step is not in a state for this action.', $c);
        }
        $replay = $this->replay($p['user_id'], 'actOnWorkflowStep.'.$stepAction, $key, [...$v, 'step_id' => $stepId]);
        if ($replay !== null) {
            return $replay['match'] ? $this->response($this->stepPayload((string) $step->id), 200, $c) : $this->problem(409, 'idempotency-conflict', 'Idempotency-Key was already used for a different request.', $c);
        }

        $now = now();
        $updates = ['lock_version' => $expected + 1, 'updated_at' => $now];
        if ($stepAction === 'reassign') {
            $updates['assignee_user_id'] = $targetUserId;
        }
        DB::table('workflow_step_instances')->where('id', $step->id)->where('lock_version', $expected)->update($updates);
        $this->outbox->append(Str::uuid7()->toString(), (string) $step->workflow_instance_id, 'workflow.step.'.$stepAction.'.v1', [
            'workflow_step_id' => (string) $step->id,
            'target_user_id' => is_string($targetUserId) ? $targetUserId : null,
            'reason' => trim($v['reason']),
            'actor_user_id' => $p['user_id'],
            'correlation_id' => $c,
        ]);
        $this->remember($p['user_id'], 'actOnWorkflowStep.'.$stepAction, $key, [...$v, 'step_id' => $stepId], (string) $step->id);

        return $this->response($this->stepPayload((string) $step->id, ['action' => $stepAction]), 200, $c);
    }

    public function cancelInstance(Request $request, string $instanceId): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }
        $key = $this->commandHeaders($request);
        if ($key === '') {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $c);
        }
        $v = $request->json()->all();
        if (! is_string($v['reason'] ?? null) || trim($v['reason']) === '' || mb_strlen($v['reason']) > 2000) {
            return $this->problem(422, 'invalid-workflow-cancel', 'The request body is invalid.', $c);
        }
        $instance = DB::table('workflow_instances')->where('id', $instanceId)->first();
        if ($instance === null) {
            return $this->problem(404, 'resource-not-found', 'The workflow instance is not available.', $c);
        }
        if (($deny = $this->denyUnlessAllowed($p, 'workflow.cancel', (string) $instance->id, (string) $instance->state, $c)) !== null) {
            return $deny;
        }
        $expected = $this->versionFromMatch($request);
        if ($expected === null || $expected !== (int) $instance->lock_version) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        }
        if ($instance->state !== 'running') {
            return $this->problem(409, 'workflow-instance-invalid-state', 'The workflow instance is not in a state for this action.', $c);
        }
        $replay = $this->replay($p['user_id'], 'cancelWorkflow', $key, [...$v, 'instance_id' => $instanceId]);
        if ($replay !== null) {
            $current = DB::table('workflow_instances')->where('id', $instanceId)->first();

            return $replay['match'] ? $this->response(['instance' => (array) $current], 200, $c, (int) $current->lock_version) : $this->problem(409, 'idempotency-conflict', 'Idempotency-Key was already used for a different request.', $c);
        }

        $now = now();
        DB::transaction(function () use ($instance, $expected, $now, $v, $p, $c): void {
            DB::table('workflow_instances')->where('id', $instance->id)->where('lock_version', $expected)->update([
                'state' => 'cancelled',
                'completed_at' => $now,
                'lock_version' => $expected + 1,
                'updated_at' => $now,
            ]);
            DB::table('workflow_step_instances')->where('workflow_instance_id', $instance->id)->whereNotIn('state', ['completed', 'cancelled'])->update(['state' => 'cancelled', 'updated_at' => $now]);
            $this->outbox->append(Str::uuid7()->toString(), (string) $instance->id, 'workflow.instance.cancelled.v1', [
                'workflow_instance_id' => (string) $instance->id,
                'reason' => trim($v['reason']),
                'actor_user_id' => $p['user_id'],
                'correlation_id' => $c,
            ]);
        });
        $this->remember($p['user_id'], 'cancelWorkflow', $key, [...$v, 'instance_id' => $instanceId], $instanceId);

        return $this->response(['instance' => (array) DB::table('workflow_instances')->where('id', $instanceId)->first()], 200, $c, $expected + 1);
    }

    /**
     * Holding `workflow.decide` says the principal may approve; it does not say
     * this step is theirs. Steps recorded before the assignee column existed
     * carry no owner and stay governed by the capability alone.
     */
    private function denyUnlessAssignee(object $step, array $principal, string $correlationId): ?JsonResponse
    {
        $assignee = $step->assignee_user_id ?? null;

        return ! is_string($assignee) || $assignee === $principal['user_id']
            ? null
            : $this->problem(403, 'access-denied', 'The step is assigned to another approver.', $correlationId);
    }

    private function denyUnlessAllowed(array $principal, string $capability, ?string $recordId, ?string $state, string $correlationId): ?JsonResponse
    {
        $decision = $this->access->decide(
            [
                'user_id' => $principal['user_id'],
                'facility_id' => $principal['facility_id'] ?? null,
                'organization_unit_ids' => array_filter([$principal['facility_id'] ?? null]),
                'correlation_id' => $correlationId,
            ],
            $capability,
            new RecordFacts(
                ownerFacilityId: $principal['facility_id'] ?? null,
                resourceType: 'workflow_instance',
                classification: 'internal',
                clusterId: $this->tenantClusterId(),
                recordId: $recordId,
                lifecycleState: $state,
            ),
        );

        return $decision->isAllowed() ? null : $this->problem(403, 'access-denied', 'Access denied.', $correlationId);
    }

    private function tenantClusterId(): ?string
    {
        $id = DB::table('clusters')->orderBy('code')->value('id');

        return is_string($id) ? $id : null;
    }

    private function stepPayload(string $stepId, array $extra = []): array
    {
        $step = DB::table('workflow_step_instances')->where('id', $stepId)->first();

        return ['step' => $step === null ? null : (array) $step, ...$extra];
    }

    private function replay(string $principalId, string $operation, string $key, array $payload): ?array
    {
        $row = DB::table('workflow_idempotency_keys')->where([
            'principal_id' => $principalId,
            'operation' => $operation,
            'key_hash' => hash('sha256', $key),
        ])->first();

        return $row === null ? null : ['match' => $row->request_hash === hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))];
    }

    private function remember(string $principalId, string $operation, string $key, array $payload, string $resourceId): void
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

    private function showDefinition(string $id, string $c, int $status = 200): mixed
    {
        $r = DB::table('workflow_definitions')->where('id', $id)->first();

        return $r === null ? $this->problem(404, 'resource-not-found', 'The workflow definition is not available.', $c) : $this->response(['definition' => (array) $r, 'version' => $this->decode((array) DB::table('workflow_versions')->where('workflow_definition_id', $id)->orderByDesc('version_number')->first())], $status, $c, 1);
    }

    private function decode(array $r): array
    {
        if (isset($r['graph_document']) && is_string($r['graph_document'])) {
            $r['graph_document'] = json_decode($r['graph_document'], true, 512, JSON_THROW_ON_ERROR);
        }

        return $r;
    }
}
