<?php

namespace App\Http\Controllers\Authorization;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Http\AuthorizationApi;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

final class DecideAccessController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = AuthorizationApi::correlationId($request);
        if ($correlationId === null) {
            return AuthorizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return AuthorizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        $input = $request->json()->all();
        if (array_diff(array_keys($input), ['action', 'access_context', 'record_facts']) !== []
            || ! is_string($input['action'] ?? null)
            || ! is_array($input['access_context'] ?? null)
            || ! is_array($input['record_facts'] ?? null)) {
            return AuthorizationApi::problem(422, 'invalid-access-decision', 'Unprocessable Entity', 'The access decision payload is invalid.', $correlationId);
        }
        $context = $input['access_context'];
        $factsInput = $input['record_facts'];
        if (($context['subject_id'] ?? null) !== $principal['user_id']
            || ($context['correlation_id'] ?? null) !== $correlationId
            || ! AuthorizationApi::isUuidV7($context['tenant_id'] ?? null)
            || ! is_string($factsInput['record_type'] ?? null)
            || ! is_string($factsInput['facts_version'] ?? null)
            || ! is_string($factsInput['classification'] ?? null)
            || ! AuthorizationApi::isUuidV7($factsInput['record_id'] ?? null)) {
            return AuthorizationApi::problem(403, 'access-context-mismatch', 'Forbidden', 'The access context is not valid for this principal.', $correlationId);
        }
        if (! $this->validUuidOrNull($factsInput['owner_facility_id'] ?? null)
            || ! $this->validUuidOrNull($factsInput['owner_organization_unit_id'] ?? null)) {
            return AuthorizationApi::problem(422, 'invalid-access-decision', 'Unprocessable Entity', 'The record facts are invalid.', $correlationId);
        }

        $facts = new RecordFacts(
            ownerFacilityId: $factsInput['owner_facility_id'] ?? null,
            resourceType: $factsInput['record_type'],
            classification: $factsInput['classification'],
            factsVersion: $factsInput['facts_version'],
            organizationUnitId: $factsInput['owner_organization_unit_id'] ?? null,
        );
        if (! $this->access->decide($principal, 'identity.account.read', $facts)->isAllowed()) {
            return AuthorizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }
        $decision = $this->access->decide($principal, $input['action'], $facts);
        $decisionId = Str::uuid7()->toString();
        $traceId = Str::uuid7()->toString();
        $evaluatedAt = now()->utc()->format('Y-m-d\TH:i:s.v\Z');
        $safeContext = [
            'subject_id' => $principal['user_id'],
            'tenant_id' => $context['tenant_id'],
            'organization_unit_ids' => is_array($context['organization_unit_ids'] ?? null) ? array_values($context['organization_unit_ids']) : [],
            'roles' => is_array($context['roles'] ?? null) ? array_values($context['roles']) : [],
            'clearance' => is_string($context['clearance'] ?? null) ? $context['clearance'] : 'internal',
            'break_glass' => (bool) ($context['break_glass'] ?? false),
            'correlation_id' => $correlationId,
        ];
        $payload = [
            'decision_id' => $decisionId,
            'decision' => $decision->decision,
            'action' => $decision->action,
            'resource_type' => $decision->resourceType,
            'resource_id' => $factsInput['record_id'],
            'reason_codes' => array_values(array_unique($decision->reasonCodes)),
            'policy_version' => $decision->policyVersion,
            'facts_version' => $decision->factsVersion,
            'authorization_trace_id' => $traceId,
            'evaluated_at' => $evaluatedAt,
            'correlation_id' => $correlationId,
            'classification' => $decision->classification,
            'access_context' => $safeContext,
        ];
        try {
            DB::transaction(function () use ($principal, $factsInput, $safeContext, $decisionId, $traceId, $evaluatedAt, $correlationId, $decision): void {
                DB::table('access_decisions')->insert([
                    'id' => $decisionId,
                    'decision' => $decision->decision,
                    'action' => $decision->action,
                    'resource_type' => $decision->resourceType,
                    'resource_id' => $factsInput['record_id'],
                    'reason_codes' => json_encode($decision->reasonCodes, JSON_THROW_ON_ERROR),
                    'policy_version' => $decision->policyVersion,
                    'facts_version' => $decision->factsVersion,
                    'authorization_trace_id' => $traceId,
                    'evaluated_at' => str_replace('T', ' ', substr($evaluatedAt, 0, 23)),
                    'correlation_id' => $correlationId,
                    'classification' => $decision->classification,
                    'access_context' => json_encode($safeContext, JSON_THROW_ON_ERROR),
                    'actor_user_id' => $principal['user_id'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($decision->isAllowed() && in_array($decision->classification, ['confidential', 'top_secret'], true)) {
                    DB::table('sensitive_access_events')->insert([
                        'id' => Str::uuid7()->toString(),
                        'access_decision_id' => $decisionId,
                        'actor_user_id' => $principal['user_id'],
                        'original_actor_user_id' => $principal['user_id'],
                        'resource_type' => $decision->resourceType,
                        'resource_id' => $factsInput['record_id'],
                        'action' => $decision->action,
                        'classification_code' => $decision->classification,
                        'correlation_id' => $correlationId,
                        'source_ip' => null,
                        'device_fingerprint_hash' => null,
                        'idempotency_key_hash' => hash('sha256', $decisionId),
                        'occurred_at' => now(),
                        'recorded_at' => now(),
                    ]);
                }
            });
        } catch (QueryException) {
            return AuthorizationApi::problem(500, 'authorization-write-failed', 'Internal Server Error', 'The access decision could not be recorded safely.', $correlationId);
        }

        return response()->json($payload)->header('X-Correlation-ID', $correlationId);
    }

    private function validUuidOrNull(mixed $value): bool
    {
        return $value === null || AuthorizationApi::isUuidV7($value);
    }
}
