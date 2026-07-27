<?php

namespace Modules\WorkDefinitions\Features\Definition\Http;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use JsonException;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\WorkDefinitions\Features\Definition\Handler\WorkDefinitionMutator;
use Shared\Http\HttpSupport;
use stdClass;

final class WorkDefinitionController
{
    use HttpSupport;

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $resolver,
        private readonly WorkDefinitionMutator $mutator,
    ) {}

    private function gate(array $p, string $capability, string $c): ?JsonResponse
    {
        return $this->mutator->gate($p, $capability, $c)
            ? null
            : $this->problem(403, 'access-denied', 'Access denied.', $c);
    }

    private function encodeWorkDefinitionCursor(string $createdAt, string $id, int $limit): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 1,
            'after' => ['created_at' => $createdAt, 'id' => $id],
            'query' => ['limit' => $limit],
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{created_at: string, id: string} */
    private function decodeWorkDefinitionCursor(string $cursor, int $limit): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new InvalidArgumentException('The work definition cursor is invalid.');
        }
        if (! is_array($payload)
            || array_keys($payload) !== ['version', 'after', 'query']
            || $payload['version'] !== 1
            || ! is_array($payload['after'])
            || array_keys($payload['after']) !== ['created_at', 'id']
            || ! is_string($payload['after']['created_at'])
            || ! is_string($payload['after']['id'])
            || ! is_array($payload['query'])
            || $payload['query'] !== ['limit' => $limit]) {
            throw new InvalidArgumentException('The work definition cursor is invalid.');
        }

        return ['created_at' => $payload['after']['created_at'], 'id' => $payload['after']['id']];
    }

    public function index(Request $request): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if (($p = $this->principal($request, $this->resolver)) === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }
        if (($deny = $this->gate($p, 'work_definition.read', $c)) !== null) {
            return $deny;
        }

        $limit = (int) $request->query('limit', 25);
        if ($limit < 1 || $limit > 100) {
            return $this->problem(400, 'invalid-pagination', 'The collection parameters are invalid.', $c);
        }
        $cursor = $request->query('cursor');
        if (is_string($cursor) && $cursor !== '') {
            try {
                $after = $this->decodeWorkDefinitionCursor($cursor, $limit);
            } catch (InvalidArgumentException) {
                return $this->problem(400, 'invalid-pagination', 'The collection parameters are invalid.', $c);
            }
        } else {
            $after = null;
        }

        $query = \Illuminate\Support\Facades\DB::table('work_definitions')->orderBy('created_at')->orderBy('id');
        if ($after !== null) {
            $query->where(function ($query) use ($after): void {
                $query->where('created_at', '>', $after['created_at'])
                    ->orWhere(function ($query) use ($after): void {
                        $query->where('created_at', $after['created_at'])
                            ->where('id', '>', $after['id']);
                    });
            });
        }
        $rows = $query->limit($limit + 1)->get();
        $hasNextPage = $rows->count() > $limit;
        if ($hasNextPage) {
            $rows->pop();
        }
        $items = $rows->map(fn ($r) => (array) $r)->values()->all();
        $lastRow = $rows->last();

        return response()->json([
            'items' => $items,
            'next_cursor' => $hasNextPage && $lastRow instanceof stdClass
                ? $this->encodeWorkDefinitionCursor((string) $lastRow->created_at, (string) $lastRow->id, $limit)
                : null,
        ])->header('X-Correlation-ID', $c);
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
        if (($deny = $this->gate($p, 'work_definition.create', $c)) !== null) {
            return $deny;
        }
        $v = $request->json()->all();
        if (! is_string($v['code'] ?? null) || preg_match('/\A[a-z][a-z0-9-]{1,95}\z/', $v['code']) !== 1 || ! is_string($v['name'] ?? null) || $v['name'] === '' || ! in_array($v['default_classification'] ?? null, ['public', 'internal', 'confidential', 'top_secret'], true)) {
            return $this->problem(422, 'invalid-work-definition', 'The request body is invalid.', $c);
        }
        $hash = hash('sha256', json_encode($v, JSON_THROW_ON_ERROR));
        $operation = 'createWorkDefinition';
        $keyHash = hash('sha256', $key);
        $existing = \Illuminate\Support\Facades\DB::table('work_definition_idempotency_keys')->where(['principal_id' => $p['user_id'], 'operation' => $operation, 'key_hash' => $keyHash])->first();
        if ($existing !== null) {
            return $existing->request_hash === $hash ? $this->showResource((string) $existing->resource_id, $c, 201) : $this->problem(409, 'idempotency-conflict', 'Idempotency-Key was already used for a different request.', $c);
        }

        $result = $this->mutator->create($v, $p, $keyHash, $hash, $operation);
        if (isset($result['conflict'])) {
            return $this->problem(409, 'work-definition-conflict', $result['conflict'], $c);
        }

        return $this->response($result['resource'], 201, $c, $result['lock_version']);
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
        if (! \Illuminate\Support\Facades\DB::table('work_definitions')->where('id', $definitionId)->exists()) {
            return $this->problem(404, 'resource-not-found', 'The work definition is not available.', $c);
        }
        $p = $this->principal($request, $this->resolver);
        if (($deny = $this->gate($p, $request->isMethod('get') ? 'work_definition.read' : 'work_definition.create', $c)) !== null) {
            return $deny;
        }
        if ($request->isMethod('get')) {
            return response()->json(['items' => \Illuminate\Support\Facades\DB::table('work_definition_versions')->where('work_definition_id', $definitionId)->orderBy('version_number')->get()->map(fn ($r) => $this->decode((array) $r)), 'next_cursor' => null])->header('X-Correlation-ID', $c);
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
        $existing = \Illuminate\Support\Facades\DB::table('work_definition_idempotency_keys')->where(['principal_id' => $p['user_id'], 'operation' => $operation, 'key_hash' => $keyHash])->first();
        if ($existing !== null) {
            return $existing->request_hash === $hash ? $this->showVersion((string) $existing->resource_id, $c, 201) : $this->problem(409, 'idempotency-conflict', 'Idempotency-Key was already used for a different request.', $c);
        }

        $result = $this->mutator->createVersion(
            $definitionId,
            $v['schema_document'],
            $v['field_policy_key'],
            $v['change_summary'] ?? null,
            $p,
            $keyHash,
            $hash,
            $operation,
        );

        return $this->response($this->decode($result['resource']), 201, $c, $result['lock_version']);
    }

    public function transition(Request $request, string $versionId, string $action): mixed
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
        $row = \Illuminate\Support\Facades\DB::table('work_definition_versions')->where('id', $versionId)->first();
        if ($row === null) {
            return $this->problem(404, 'resource-not-found', 'The work definition version is not available.', $c);
        }
        if (($deny = $this->gate($p, $action === 'publish' ? 'work_definition.publish' : 'work_definition.update', $c)) !== null) {
            return $deny;
        }
        $expected = $this->versionFromMatch($request);
        if ($expected === null) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        }
        $target = ['test' => 'tested', 'approve' => 'approved', 'sign' => 'signed', 'publish' => 'published'][$action] ?? null;
        if ($target === null || ($action !== 'publish' && $row->status !== ['test' => 'draft', 'approve' => 'tested', 'sign' => 'approved'][$action])) {
            return $this->problem(409, 'invalid-lifecycle-transition', 'The lifecycle transition is not allowed.', $c);
        }

        $result = $this->mutator->transition($versionId, $expected, $action, $target, $row->published_at);
        if (($result['stale'] ?? false) === true) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        }
        if (! $result['ok']) {
            return $this->problem(409, 'work-definition-conflict', $result['conflict'] ?? 'conflict', $c);
        }

        return $this->showVersion($versionId, $c, 200);
    }

    public function show(Request $request, string $definitionId): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if ($this->principal($request, $this->resolver) === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }

        return $this->showResource($definitionId, $c);
    }

    public function showVersionRoute(Request $request, string $versionId): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if ($this->principal($request, $this->resolver) === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }

        return $this->showVersion($versionId, $c);
    }

    private function showResource(string $id, string $c, int $status = 200): mixed
    {
        $r = \Illuminate\Support\Facades\DB::table('work_definitions')->where('id', $id)->first();

        return $r === null ? $this->problem(404, 'resource-not-found', 'The work definition is not available.', $c) : $this->response((array) $r, $status, $c, (int) $r->lock_version);
    }

    private function showVersion(string $id, string $c, int $status = 200): mixed
    {
        $r = \Illuminate\Support\Facades\DB::table('work_definition_versions')->where('id', $id)->first();

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
