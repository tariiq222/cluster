<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Shared\Contracts\TransactionalOutbox;

final class WorkDefinitionController
{
    use HttpSupport;

    public function __construct(private readonly ResolveDevelopmentFixturePrincipal $resolver, private readonly TransactionalOutbox $outbox) {}

    public function index(Request $request): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if (($p = $this->principal($request, $this->resolver)) === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }

        return response()->json(['items' => DB::table('work_definitions')->orderBy('created_at')->get()->map(fn ($r) => (array) $r), 'next_cursor' => null])->header('X-Correlation-ID', $c);
    }

    public function store(Request $request): mixed
    {
        [$c, $p, $key] = [$this->correlation($request), $this->principal($request, $this->resolver), $this->commandHeaders($request)];
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }
        if ($key === '') {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $c);
        }
        $v = $request->json()->all();
        if (! is_string($v['code'] ?? null) || preg_match('/\A[a-z][a-z0-9_]{1,95}\z/', $v['code']) !== 1 || ! is_string($v['name'] ?? null) || $v['name'] === '' || ! is_string($v['source_record_type'] ?? null)) {
            return $this->problem(422, 'invalid-work-definition', 'The request body is invalid.', $c);
        }
        $hash = hash('sha256', json_encode($v, JSON_THROW_ON_ERROR));
        $operation = 'createWorkDefinition';
        $keyHash = hash('sha256', $key);
        $existing = DB::table('work_definition_idempotency_keys')->where(['principal_id' => $p['user_id'], 'operation' => $operation, 'key_hash' => $keyHash])->first();
        if ($existing !== null) {
            return $existing->request_hash === $hash ? $this->showResource((string) $existing->resource_id, $c, 201) : $this->problem(409, 'idempotency-conflict', 'Idempotency-Key was already used for a different request.', $c);
        }
        try {
            $row = DB::transaction(function () use ($v, $p, $hash, $keyHash, $operation): array {
                $id = Str::uuid7()->toString();
                $now = now();
                DB::table('work_definitions')->insert(['id' => $id, 'code' => $v['code'], 'name' => $v['name'], 'created_by_user_id' => $p['user_id'], 'status' => 'active', 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
                DB::table('work_definition_idempotency_keys')->insert(['principal_id' => $p['user_id'], 'operation' => $operation, 'key_hash' => $keyHash, 'request_hash' => $hash, 'resource_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
                $this->outbox->append(Str::uuid7()->toString(), $id, 'work_definition.created.v1', ['work_definition_id' => $id]);

                return (array) DB::table('work_definitions')->where('id', $id)->first();
            });

            return $this->response($row, 201, $c, 1);
        } catch (\Throwable $e) {
            return $this->problem(409, 'work-definition-conflict', $e->getMessage(), $c);
        }
    }

    public function versions(Request $request, string $definitionId): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if ($this->principal($request, $this->resolver) === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }
        if (! DB::table('work_definitions')->where('id', $definitionId)->exists()) {
            return $this->problem(404, 'resource-not-found', 'The work definition is not available.', $c);
        }
        if ($request->isMethod('get')) {
            return response()->json(['items' => DB::table('work_definition_versions')->where('work_definition_id', $definitionId)->orderBy('version_number')->get()->map(fn ($r) => $this->decode((array) $r)), 'next_cursor' => null])->header('X-Correlation-ID', $c);
        }
        $p = $this->principal($request, $this->resolver);
        $key = $this->commandHeaders($request);
        if ($key === '') {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $c);
        }
        $v = $request->json()->all();
        if (! is_array($v['schema_document'] ?? null) || $v['schema_document'] === [] || ! is_string($v['field_policy_key'] ?? null)) {
            return $this->problem(422, 'invalid-work-definition-version', 'The request body is invalid.', $c);
        }
        $hash = hash('sha256', json_encode($v, JSON_THROW_ON_ERROR));
        $operation = 'createWorkDefinitionVersion';
        $keyHash = hash('sha256', $key);
        $existing = DB::table('work_definition_idempotency_keys')->where(['principal_id' => $p['user_id'], 'operation' => $operation, 'key_hash' => $keyHash])->first();
        if ($existing !== null) {
            return $existing->request_hash === $hash ? $this->showVersion((string) $existing->resource_id, $c, 201) : $this->problem(409, 'idempotency-conflict', 'Idempotency-Key was already used for a different request.', $c);
        }
        $row = DB::transaction(function () use ($definitionId, $p, $v, $hash, $keyHash, $operation): array {
            $id = Str::uuid7()->toString();
            $now = now();
            $n = (int) DB::table('work_definition_versions')->where('work_definition_id', $definitionId)->max('version_number') + 1;
            DB::table('work_definition_versions')->insert(['id' => $id, 'work_definition_id' => $definitionId, 'version_number' => $n, 'status' => 'draft', 'schema_document' => json_encode($v['schema_document'], JSON_THROW_ON_ERROR), 'field_policy_key' => $v['field_policy_key'], 'schema_hash' => hash('sha256', json_encode($v['schema_document'], JSON_THROW_ON_ERROR)), 'change_summary' => $v['change_summary'] ?? null, 'created_by_user_id' => $p['user_id'], 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('work_definition_idempotency_keys')->insert(['principal_id' => $p['user_id'], 'operation' => $operation, 'key_hash' => $keyHash, 'request_hash' => $hash, 'resource_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
            $this->outbox->append(Str::uuid7()->toString(), $id, 'work_definition.version.created.v1', ['version_id' => $id]);

            return (array) DB::table('work_definition_versions')->where('id', $id)->first();
        });

        return $this->response($this->decode($row), 201, $c, 1);
    }

    public function transition(Request $request, string $versionId, string $action): mixed
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
        } $row = DB::table('work_definition_versions')->where('id', $versionId)->first();
        if ($row === null) {
            return $this->problem(404, 'resource-not-found', 'The work definition version is not available.', $c);
        } $expected = $this->versionFromMatch($request);
        if ($expected === null || $expected !== (int) $row->lock_version) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        } $target = ['test' => 'tested', 'approve' => 'approved', 'sign' => 'signed', 'publish' => 'published'][$action] ?? null;
        if ($target === null || ($action !== 'publish' && $row->status !== ['test' => 'draft', 'approve' => 'tested', 'sign' => 'approved'][$action])) {
            return $this->problem(409, 'invalid-lifecycle-transition', 'The lifecycle transition is not allowed.', $c);
        } $now = now();
        DB::transaction(function () use ($versionId, $expected, $target, $now, $action, $row): void {
            $updated = DB::table('work_definition_versions')->where('id', $versionId)->where('lock_version', $expected)->update(['status' => $target, 'lock_version' => $expected + 1, 'published_at' => $target === 'published' ? $now : $row->published_at, 'updated_at' => $now]);
            if ($updated !== 1) {
                throw new \RuntimeException('stale');
            } $this->outbox->append(Str::uuid7()->toString(), $versionId, 'work_definition.version.'.$action.'.v1', ['version_id' => $versionId]);
        });

        return $this->showVersion($versionId, $c, 200);
    }

    public function show(Request $request, string $definitionId): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        } if ($this->principal($request, $this->resolver) === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }

        return $this->showResource($definitionId, $c);
    }

    public function showVersionRoute(Request $request, string $versionId): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        } if ($this->principal($request, $this->resolver) === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }

        return $this->showVersion($versionId, $c);
    }

    private function showResource(string $id, string $c, int $status = 200): mixed
    {
        $r = DB::table('work_definitions')->where('id', $id)->first();

        return $r === null ? $this->problem(404, 'resource-not-found', 'The work definition is not available.', $c) : $this->response((array) $r, $status, $c, (int) $r->lock_version);
    }

    private function showVersion(string $id, string $c, int $status = 200): mixed
    {
        $r = DB::table('work_definition_versions')->where('id', $id)->first();

        return $r === null ? $this->problem(404, 'resource-not-found', 'The work definition version is not available.', $c) : $this->response($this->decode((array) $r), $status, $c, (int) $r->lock_version);
    }

    private function decode(array $r): array
    {
        if (isset($r['schema_document']) && is_string($r['schema_document'])) {
            $r['schema_document'] = json_decode($r['schema_document'], true, 512, JSON_THROW_ON_ERROR);
        }

        return $r;
    }
}
