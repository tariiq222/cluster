<?php

namespace Modules\Workflow\Features\WorkflowLifecycle\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Workflow\Contracts\ResolveWorkflowSourceAuthorizationFacts;
use Modules\Workflow\Contracts\WorkflowSourceReference;
use Modules\Workflow\Features\Engine\Handler\RecordDecisionHandler;
use Modules\Workflow\Features\GetVisibleWorkflowInstance\Query\GetVisibleWorkflowInstance;
use Modules\Workflow\Features\ListApprovalInbox\Query\ListApprovalInbox;
use Modules\Workflow\Features\StartWorkflow\Handler\StartWorkflowHandler;
use Modules\Workflow\Features\WorkflowLifecycle\Handler\WorkflowLifecycleMutator;
use Shared\Http\HttpSupport;

final class WorkflowController
{
    use HttpSupport;

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $resolver,
        private readonly WorkflowLifecycleMutator $mutator,
        private readonly StartWorkflowHandler $starter,
        private readonly DecideAccess $access,
        private readonly GetVisibleWorkflowInstance $visibleInstances,
        private readonly ListApprovalInbox $approvalInbox,
        private readonly ResolveWorkflowSourceAuthorizationFacts $sourceFacts,
        private readonly ?RecordDecisionHandler $recordDecision = null,
    ) {}

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
        $graph = ['nodes' => [['key' => 'start', 'type' => 'start'], ['key' => 'review', 'type' => 'work_item', 'configuration' => ['title' => $v['name']]], ['key' => 'end', 'type' => 'end']], 'transitions' => [['from' => 'start', 'to' => 'review'], ['from' => 'review', 'to' => 'end']], 'decision_policy' => ['default' => 'owner']];
        $requestHash = hash('sha256', json_encode($v, JSON_THROW_ON_ERROR));
        $keyHash = hash('sha256', $key);
        $existing = DB::table('workflow_idempotency_keys')->where(['principal_id' => $p['user_id'], 'operation' => 'createWorkflowDefinition', 'key_hash' => $keyHash])->first();
        if ($existing !== null) {
            return $existing->request_hash === $requestHash ? $this->showDefinition((string) $existing->resource_id, $c, 201) : $this->problem(409, 'idempotency-conflict', 'Idempotency-Key was already used for a different request.', $c);
        }
        $result = $this->mutator->createDefinition($v, (string) $p['user_id'], $graph, $requestHash, $keyHash);
        if (! $result['ok']) {
            return $this->problem(409, 'workflow-definition-conflict', $result['conflict'], $c);
        }

        return $this->response(['definition' => $result['definition'], 'version' => $result['version']], 201, $c, 1);
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
        }
        $p = $this->principal($request, $this->resolver);
        $graph = ['nodes' => $v['nodes'], 'transitions' => $v['transitions'], 'decision_policy' => $v['decision_policy'] ?? []];
        $result = $this->mutator->createVersion($definitionId, (string) $p['user_id'], $graph);
        if (! $result['ok']) {
            return $this->problem(409, 'workflow-version-conflict', $result['conflict'], $c);
        }
        $versionRow = (array) DB::table('workflow_versions')->where('id', $result['version_id'])->first();

        return $this->response($this->decode($versionRow), 201, $c, 1);
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
        }
        $result = $this->mutator->publishVersion($versionId);
        if (! $result['ok']) {
            return $this->problem(409, 'invalid-lifecycle-transition', $result['conflict'], $c);
        }

        return $this->response($this->decode($result['version']), 200, $c, 1);
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
            $q = $request->query();
            if (array_diff(array_keys($q), ['cursor', 'limit', 'state']) !== [] || ($request->query('state') !== null && (! is_string($request->query('state')) || ! in_array($request->query('state'), ['running', 'completed', 'cancelled'], true)))) {
                return $this->problem(400, 'invalid-pagination', 'The collection parameters are invalid.', $c);
            }
            $limit = $request->query('limit', 50);
            if (filter_var($limit, FILTER_VALIDATE_INT) === false || (int) $limit < 1 || (int) $limit > 100) {
                return $this->problem(400, 'invalid-pagination', 'The collection parameters are invalid.', $c);
            }
            try {
                $page = $this->visibleInstances->owned((string) $p['user_id'], $request->query('state'), is_string($request->query('cursor')) ? $request->query('cursor') : null, (int) $limit);
            } catch (\InvalidArgumentException) {
                return $this->problem(400, 'invalid-pagination', 'The collection parameters are invalid.', $c);
            }

            return response()->json($page)->header('X-Correlation-ID', $c);
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
        } $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }
        $visible = $this->visibleInstances->fetch($instanceId, (string) $p['user_id']);
        if ($visible !== null) {
            return response()->json($visible)->header('X-Correlation-ID', $c)->header('ETag', '"'.$visible['lock_version'].'"');
        }

        // Do not disclose whether a foreign id exists: only an explicitly scoped
        // workflow.approve decision can widen the personal visible set.
        $candidate = $this->visibleInstances->find($instanceId);
        $approvalFacts = $candidate === null ? null : $this->workflowApprovalFacts($candidate);
        if ($candidate === null || $approvalFacts === null || ! $this->isAllowed($p, 'workflow.approve', (string) $candidate->id, (string) $candidate->state, $c, $approvalFacts)) {
            return $this->problem(404, 'resource-not-found', 'The workflow instance is not available.', $c);
        }

        $payload = $this->visibleInstances->fetchForOperations($candidate);

        return response()->json($payload)->header('X-Correlation-ID', $c)->header('ETag', '"'.$payload['lock_version'].'"');
    }

    public function showStep(Request $request, string $stepId): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        } $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }
        $step = DB::table('workflow_step_instances')->where('id', $stepId)->first();
        if ($step === null) {
            return $this->problem(404, 'resource-not-found', 'The workflow step is not available.', $c);
        } $instance = $this->visibleInstances->find((string) $step->workflow_instance_id);
        if ($instance === null) {
            return $this->problem(404, 'resource-not-found', 'The workflow step is not available.', $c);
        }
        $own = $step->assignee_user_id === $p['user_id'];
        $facts = $own ? null : $this->workflowApprovalFacts($instance);
        if (! $own && ($facts === null || ! $this->isAllowed($p, 'workflow.approve', (string) $instance->id, (string) $instance->state, $c, $facts))) {
            return $this->problem(404, 'resource-not-found', 'The workflow step is not available.', $c);
        }

        return response()->json(['step_id' => (string) $step->id, 'workflow_instance_id' => (string) $instance->id, 'source_type' => (string) $instance->source_type, 'source_id' => (string) $instance->source_id, 'state' => (string) $step->state, 'assignee_user_id' => $step->assignee_user_id, 'created_at' => $this->utcDateTime((string) $step->created_at), 'lock_version' => (int) $step->lock_version, 'allowed_actions' => $this->inboxAllowedActions($step, $p, $c), 'workflow_instance' => ['id' => (string) $instance->id, 'workflow_version_id' => (string) $instance->workflow_version_id, 'source_module' => (string) $instance->source_module, 'source_type' => (string) $instance->source_type, 'source_id' => (string) $instance->source_id, 'state' => (string) $instance->state, 'lock_version' => (int) $instance->lock_version]])->header('X-Correlation-ID', $c)->header('ETag', '"'.$step->lock_version.'"');
    }

    public function listInbox(Request $request): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }
        $query = $request->query();
        if (array_diff(array_keys($query), ['assignee', 'assignee_user_id', 'state', 'limit', 'cursor']) !== []) {
            return $this->problem(400, 'invalid-pagination', 'The collection parameters are invalid.', $c);
        }
        $assignee = $request->query('assignee');
        $assigneeUserId = $request->query('assignee_user_id');
        if ($assignee !== null && $assignee !== 'me') {
            return $this->problem(400, 'invalid-assignee', 'The assignee filter is invalid.', $c);
        }
        if ($assigneeUserId !== null && (! is_string($assigneeUserId) || $assigneeUserId === '' || $assignee === 'me')) {
            return $this->problem(400, 'invalid-assignee', 'The assignee filter is invalid.', $c);
        }
        $operationsInbox = is_string($assigneeUserId);
        if ($operationsInbox) {
            $filterAssignee = $assigneeUserId;
        } else {
            $filterAssignee = (string) $p['user_id'];
        }

        $state = $request->query('state');
        if ($state !== null && (! is_string($state) || ! in_array($state, ['waiting', 'active', 'completed', 'rejected', 'returned', 'cancelled', 'all'], true))) {
            return $this->problem(400, 'invalid-state', 'The state filter is invalid.', $c);
        }
        $limitValue = $request->query('limit', 50);
        if (filter_var($limitValue, FILTER_VALIDATE_INT) === false || (int) $limitValue < 1 || (int) $limitValue > 100) {
            return $this->problem(400, 'invalid-pagination', 'The collection parameters are invalid.', $c);
        }
        $cursor = $request->query('cursor');
        if ($cursor !== null && (! is_string($cursor) || $cursor === '')) {
            return $this->problem(400, 'invalid-pagination', 'The collection parameters are invalid.', $c);
        }

        try {
            $page = $this->approvalInbox->execute(
                $filterAssignee,
                $state,
                (int) $limitValue,
                $cursor,
                fn (object $step): array => $this->inboxAllowedActions($step, $p, $c),
                $operationsInbox ? fn (array $steps): array => $this->visibleOperationsInboxStepIds($steps, $p, $c) : null,
            );
        } catch (\InvalidArgumentException) {
            return $this->problem(400, 'invalid-pagination', 'The collection parameters are invalid.', $c);
        }

        return response()->json($page)->header('X-Correlation-ID', $c);
    }

    /** @return list<string> */
    private function inboxAllowedActions(object $step, array $principal, string $correlationId): array
    {
        if (! in_array($step->state, ['waiting', 'active'], true) || $step->assignee_user_id !== $principal['user_id']) {
            return [];
        }

        $actions = [];
        foreach (['approve' => 'workflow.decide', 'reject' => 'workflow.decide', 'return' => 'workflow.decide', 'reassign' => 'workflow.reassign', 'escalate' => 'workflow.escalate'] as $action => $capability) {
            if ($this->isAllowed($principal, $capability, (string) $step->id, (string) $step->state, $correlationId)) {
                $actions[] = $action;
            }
        }

        return $actions;
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
        $replay = $this->mutator->replay((string) $p['user_id'], 'recordWorkflowDecision', $key, $v);
        if ($replay !== null) {
            return $replay['match'] ? $this->response($this->stepPayload((string) $step->id), 201, $c) : $this->problem(409, 'idempotency-conflict', 'Idempotency-Key was already used for a different request.', $c);
        }
        $newState = in_array($v['decision'], ['approve', 'accept'], true) ? 'completed' : ($v['decision'] === 'return' ? 'returned' : 'rejected');
        $result = $this->mutator->recordStepDecision(
            (array) $step,
            $instance === null ? null : (array) $instance,
            (string) $expected,
            $newState,
            (string) $v['decision'],
            is_string($v['reason'] ?? null) ? $v['reason'] : null,
            (string) $p['user_id'],
            $c,
        );
        if (! $result['ok']) {
            return $this->problem(409, 'workflow-step-conflict', $result['conflict'], $c);
        }
        // Persist a queryable decision row outside the transaction so the
        // reason survives outbox trim/retention. The handler is optional to
        // keep this controller invokable when only the legacy outbox path is
        // wired (older deployments or staged rollouts).
        if ($this->recordDecision !== null) {
            $this->recordDecision->record((string) $step->id, (string) $v['decision'], is_string($v['reason'] ?? null) ? $v['reason'] : null, (string) $p['user_id'], $c);
        }
        $this->mutator->remember((string) $p['user_id'], 'recordWorkflowDecision', $key, $v, (string) $step->id);

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
        $replay = $this->mutator->replay((string) $p['user_id'], 'actOnWorkflowStep.'.$stepAction, $key, [...$v, 'step_id' => $stepId]);
        if ($replay !== null) {
            return $replay['match'] ? $this->response($this->stepPayload((string) $step->id), 200, $c) : $this->problem(409, 'idempotency-conflict', 'Idempotency-Key was already used for a different request.', $c);
        }

        $result = $this->mutator->actOnStep(
            (array) $step,
            (string) $expected,
            $stepAction,
            is_string($targetUserId) ? $targetUserId : null,
            trim($v['reason']),
            (string) $p['user_id'],
            $c,
        );
        if (! $result['ok']) {
            return $this->problem(409, 'workflow-step-action-conflict', $result['conflict'], $c);
        }
        $this->mutator->remember((string) $p['user_id'], 'actOnWorkflowStep.'.$stepAction, $key, [...$v, 'step_id' => $stepId], (string) $step->id);

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
        $replay = $this->mutator->replay((string) $p['user_id'], 'cancelWorkflow', $key, [...$v, 'instance_id' => $instanceId]);
        if ($replay !== null) {
            $current = DB::table('workflow_instances')->where('id', $instanceId)->first();

            return $replay['match'] ? $this->response(['instance' => (array) $current], 200, $c, (int) $current->lock_version) : $this->problem(409, 'idempotency-conflict', 'Idempotency-Key was already used for a different request.', $c);
        }

        $result = $this->mutator->cancelInstance(
            (array) $instance,
            (string) $expected,
            trim($v['reason']),
            (string) $p['user_id'],
            $c,
        );
        if (! $result['ok']) {
            return $this->problem(409, 'workflow-cancel-conflict', $result['conflict'], $c);
        }
        $this->mutator->remember((string) $p['user_id'], 'cancelWorkflow', $key, [...$v, 'instance_id' => $instanceId], $instanceId);

        return $this->response(['instance' => (array) DB::table('workflow_instances')->where('id', $instanceId)->first()], 200, $c, ((int) $expected) + 1);
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
        return $this->isAllowed($principal, $capability, $recordId, $state, $correlationId)
            ? null
            : $this->problem(403, 'access-denied', 'Access denied.', $correlationId);
    }

    private function isAllowed(array $principal, string $capability, ?string $recordId, ?string $state, string $correlationId, ?RecordFacts $facts = null): bool
    {
        return $this->access->decide(
            [
                'user_id' => $principal['user_id'],
                'facility_id' => $principal['facility_id'] ?? null,
                'organization_unit_ids' => array_filter([$principal['facility_id'] ?? null]),
                'correlation_id' => $correlationId,
            ],
            $capability,
            $facts ?? new RecordFacts(
                ownerFacilityId: $principal['facility_id'] ?? null,
                resourceType: 'workflow_instance',
                classification: 'internal',
                clusterId: $this->mutator->tenantClusterId(),
                recordId: $recordId,
                lifecycleState: $state,
            ),
        )->isAllowed();
    }

    private function workflowApprovalFacts(object $instance): ?RecordFacts
    {
        try {
            $reference = new WorkflowSourceReference(
                (string) $instance->source_module,
                (string) $instance->source_type,
                (string) $instance->source_id,
            );
            $sourceFacts = $this->sourceFacts->resolve($reference);
        } catch (\Throwable) {
            return null;
        }
        if ($sourceFacts === null) {
            return null;
        }

        return $this->workflowApprovalFactsFromSource($sourceFacts, (string) $instance->source_module, (string) $instance->source_id, (string) $instance->state);
    }

    /** @param list<object> $steps @return array<string, true> */
    private function visibleOperationsInboxStepIds(array $steps, array $principal, string $correlationId): array
    {
        $references = [];
        foreach ($steps as $step) {
            try {
                $reference = new WorkflowSourceReference((string) $step->source_module, (string) $step->source_type, (string) $step->source_id);
                $references[$reference->key()] = $reference;
            } catch (\InvalidArgumentException) {
                continue;
            }
        }
        if ($references === []) {
            return [];
        }

        // Resolve every source reference in one integration call. Authorization
        // remains per record because its ABAC decision is intentionally scoped.
        try {
            $sourceFacts = $this->sourceFacts->resolveMany(array_values($references));
        } catch (\Throwable) {
            return [];
        }
        $visible = [];
        foreach ($steps as $step) {
            try {
                $reference = new WorkflowSourceReference((string) $step->source_module, (string) $step->source_type, (string) $step->source_id);
                $facts = $sourceFacts[$reference->key()] ?? null;
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ($facts === null) {
                continue;
            }
            $approvalFacts = $this->workflowApprovalFactsFromSource($facts, (string) $step->source_module, (string) $step->source_id, (string) $step->workflow_instance_state);
            if ($this->isAllowed($principal, 'workflow.approve', (string) $step->workflow_instance_id, (string) $step->workflow_instance_state, $correlationId, $approvalFacts)) {
                $visible[(string) $step->id] = true;
            }
        }

        return $visible;
    }

    private function workflowApprovalFactsFromSource(RecordFacts $sourceFacts, string $sourceModule, string $sourceId, string $workflowState): RecordFacts
    {

        return new RecordFacts(
            ownerFacilityId: $sourceFacts->ownerFacilityId,
            resourceType: $sourceFacts->resourceType,
            classification: $sourceFacts->classification,
            factsVersion: $sourceFacts->factsVersion,
            organizationUnitId: $sourceFacts->organizationUnitId,
            recordId: $sourceFacts->recordId ?? $sourceId,
            sourceModule: $sourceModule,
            clusterId: $sourceFacts->clusterId,
            createdByUserId: $sourceFacts->createdByUserId,
            ownerUserId: $sourceFacts->ownerUserId,
            responsibleUserId: $sourceFacts->responsibleUserId,
            sharedUnitIds: $sourceFacts->sharedUnitIds,
            sharedUserIds: $sourceFacts->sharedUserIds,
            participantIds: $sourceFacts->participantIds,
            lifecycleState: $sourceFacts->lifecycleState,
            workflowState: $workflowState,
            fieldPolicyKey: $sourceFacts->fieldPolicyKey,
            workTypeVersionId: $sourceFacts->workTypeVersionId,
            legalHold: $sourceFacts->legalHold,
            lockVersion: $sourceFacts->lockVersion,
        );
    }

    private function stepPayload(string $stepId, array $extra = []): array
    {
        $step = DB::table('workflow_step_instances')->where('id', $stepId)->first();

        return ['step' => $step === null ? null : (array) $step, ...$extra];
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
