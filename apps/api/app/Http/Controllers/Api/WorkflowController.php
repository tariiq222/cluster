<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Workflow\Features\StartWorkflow\Handler\StartWorkflowHandler;
use Shared\Contracts\TransactionalOutbox;

final class WorkflowController
{
    use HttpSupport;

    public function __construct(private readonly ResolveDevelopmentFixturePrincipal $resolver, private readonly TransactionalOutbox $outbox, private readonly StartWorkflowHandler $starter) {}

    public function definitions(Request $request): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        } $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
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
        DB::table('workflow_versions')->insert(['id' => $versionId, 'workflow_definition_id' => $definitionId, 'version_number' => (int) DB::table('workflow_versions')->where('workflow_definition_id', $definitionId)->max('version_number') + 1, 'definition_state' => 'draft', 'graph_document' => json_encode($graph, JSON_THROW_ON_ERROR), 'graph_hash' => hash('sha256', json_encode($graph, JSON_THROW_ON_ERROR)), 'dsl_version' => '1', 'created_at' => $now, 'updated_at' => $now]);
        $this->outbox->append(Str::uuid7()->toString(), $versionId, 'workflow.version.created.v1', ['workflow_version_id' => $versionId, 'actor_user_id' => $p['user_id']]);

        return $this->response($this->decode((array) DB::table('workflow_versions')->where('id', $versionId)->first()), 201, $c, 1);
    }

    public function publish(Request $request, string $versionId, string $action): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        } if ($this->principal($request, $this->resolver) === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        } $key = $this->commandHeaders($request);
        if ($key === '') {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $c);
        } $row = DB::table('workflow_versions')->where('id', $versionId)->first();
        if ($row === null) {
            return $this->problem(404, 'resource-not-found', 'The workflow version is not available.', $c);
        } if ($action !== 'publish') {
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
        } if ($request->isMethod('get')) {
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
