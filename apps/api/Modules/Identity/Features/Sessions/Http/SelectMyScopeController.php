<?php

namespace Modules\Identity\Features\Sessions\Http;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Modules\Identity\Http\IdentityApi;

/**
 * PUT /api/v1/me/scope — selects one already-held effective scope. It never
 * expands access: the candidate must be present in the trusted
 * PrincipalContext, the write is optimistic (If-Match on the session scope
 * version) and idempotent (Idempotency-Key replay).
 */
final class SelectMyScopeController
{
    use ResolvesScopeSelection;

    private const OPERATION = 'identity.scope.select';

    public function __construct(private readonly ResolvePrincipalContext $principalContexts) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = IdentityApi::correlationId($request);
        if ($correlationId === null) {
            return IdentityApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }

        $context = $this->principalContexts->resolve($request);
        if ($context === null) {
            return IdentityApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }

        $session = $request->attributes->get('identity.session');
        if (! is_array($session) || ! is_string($session['session_id'] ?? null)) {
            return IdentityApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        $sessionId = $session['session_id'];

        $body = $request->json()->all();
        $scopeType = $body['scope_type'] ?? null;
        $scopeId = $body['scope_id'] ?? null;
        if (! in_array($scopeType, ['cluster', 'facility', 'unit'], true)
            || ! is_string($scopeId)
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $scopeId) !== 1) {
            return IdentityApi::problem(400, 'invalid-scope-selection', 'Bad Request', 'The scope selection is invalid.', $correlationId);
        }

        $idempotencyKey = IdentityApi::idempotencyKey($request);
        if ($idempotencyKey === null) {
            return IdentityApi::problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $correlationId);
        }

        $expectedVersion = IdentityApi::ifMatch($request);
        if ($expectedVersion === null) {
            return IdentityApi::problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current version.', $correlationId);
        }

        if (! $this->holdsScope($context, $scopeType, $scopeId)) {
            return IdentityApi::problem(403, 'scope-not-held', 'Forbidden', 'The requested scope is not held by the current principal.', $correlationId);
        }

        $requestHash = hash('sha256', json_encode(['scope_type' => $scopeType, 'scope_id' => $scopeId], JSON_THROW_ON_ERROR));
        $keyHash = hash('sha256', $idempotencyKey);
        try {
            $result = DB::transaction(function () use ($sessionId, $context, $keyHash, $requestHash, $expectedVersion, $scopeType, $scopeId): array {
                // The version check and increment must use the same row lock. A
                // read followed by an unlocked update lets two writers observe
                // the same ETag and both commit.
                $row = DB::table('identity_sessions')
                    ->where('id', $sessionId)
                    ->lockForUpdate()
                    ->first(['metadata']);

                $existing = DB::table('identity_idempotency_keys')
                    ->where('principal_id', $context->userId)
                    ->where('operation', self::OPERATION)
                    ->where('idempotency_key_hash', $keyHash)
                    ->first();
                if ($existing !== null) {
                    if ($existing->request_hash !== $requestHash) {
                        return ['status' => 'idempotency-conflict'];
                    }

                    return [
                        'status' => 'replay',
                        'payload' => json_decode((string) $existing->response_payload, true, 512, JSON_THROW_ON_ERROR),
                        'version' => (int) $existing->response_version,
                    ];
                }

                $metadata = [];
                if ($row !== null && is_string($row->metadata) && $row->metadata !== '') {
                    $decoded = json_decode($row->metadata, true);
                    $metadata = is_array($decoded) ? $decoded : [];
                }
                $currentVersion = (int) ($metadata['scope_version'] ?? 1);
                if ($currentVersion !== $expectedVersion) {
                    return ['status' => 'precondition-failed'];
                }

                $metadata['selected_scope'] = ['scope_type' => $scopeType, 'scope_id' => $scopeId];
                $newVersion = $currentVersion + 1;
                $metadata['scope_version'] = $newVersion;
                $selection = $this->scopeSelectionFor($context);
                $selectedOption = ['scope_type' => $scopeType, 'scope_id' => $scopeId, 'label' => $this->scopeLabel($scopeType === 'unit' ? 'organization_units' : ($scopeType === 'facility' ? 'facilities' : 'clusters'), $scopeId)];
                $payload = ['available_scopes' => $selection['available_scopes'], 'effective_scope' => $selectedOption];

                DB::table('identity_sessions')->where('id', $sessionId)->update([
                    'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
                DB::table('identity_idempotency_keys')->insert([
                    'principal_id' => $context->userId,
                    'operation' => self::OPERATION,
                    'idempotency_key_hash' => $keyHash,
                    'request_hash' => $requestHash,
                    'resource_type' => 'identity_session',
                    'resource_id' => $sessionId,
                    'response_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'response_version' => $newVersion,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return ['status' => 'success', 'payload' => $payload, 'version' => $newVersion];
            });
        } catch (QueryException) {
            return IdentityApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
        }

        if ($result['status'] === 'idempotency-conflict') {
            return IdentityApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
        }
        if ($result['status'] === 'precondition-failed') {
            return IdentityApi::problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current version.', $correlationId);
        }

        return response()->json($result['payload'])
            ->header('X-Correlation-ID', $correlationId)
            ->header('ETag', '"'.$result['version'].'"');
    }
}
