<?php

namespace App\Http\Controllers\Authorization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Http\AuthorizationApi;
use Modules\Authorization\Infrastructure\RbacAbacDecideAccess;
use Modules\Authorization\Infrastructure\BootstrapGatedDecideAccess;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

final class ExplainAccessDecisionController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
    ) {}

    public function __invoke(Request $request, string $decisionId): JsonResponse
    {
        $correlationId = AuthorizationApi::correlationId($request);
        if ($correlationId === null) {
            return AuthorizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if (! AuthorizationApi::isUuidV7($decisionId)) {
            return AuthorizationApi::problem(404, 'decision-not-found', 'Not Found', 'The access decision is not available.', $correlationId);
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return AuthorizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        $decision = DB::table('access_decisions')->where('id', $decisionId)->first();
        if ($decision === null) {
            return AuthorizationApi::problem(404, 'decision-not-found', 'Not Found', 'The access decision is not available.', $correlationId);
        }
        $context = json_decode((string) $decision->access_context, true);
        $targetFacilityId = is_array($context) && is_string($context['facility_id'] ?? null)
            ? $context['facility_id']
            : null;
        $targetUnitIds = is_array($context) && is_array($context['organization_unit_ids'] ?? null)
            ? array_values(array_filter($context['organization_unit_ids'], 'is_string'))
            : [];
        $targetUnitId = $targetUnitIds[0] ?? null;
        $targetFacts = new RecordFacts(
            ownerFacilityId: $targetFacilityId,
            resourceType: 'authorization_access_decision',
            classification: (string) $decision->classification,
            factsVersion: (string) $decision->facts_version,
            organizationUnitId: is_string($targetUnitId) ? $targetUnitId : null,
            recordId: (string) $decision->id,
        );
        if ($targetFacilityId === null || ! $this->authorizeWithoutPersistence(
            [...$principal, 'correlation_id' => $correlationId],
            $targetFacts,
        )) {
            return AuthorizationApi::problem(404, 'decision-not-found', 'Not Found', 'The access decision is not available.', $correlationId);
        }
        $reasonCodes = json_decode((string) $decision->reason_codes, true);
        $payload = [
            'decision_id' => $decision->id,
            'decision' => $decision->decision,
            'action' => $decision->action,
            'resource_type' => $decision->resource_type,
            'resource_id' => $decision->resource_id,
            'reason_codes' => is_array($reasonCodes) ? array_values($reasonCodes) : ['decision_record_incomplete'],
            'policy_version' => $decision->policy_version,
            'facts_version' => $decision->facts_version,
            'authorization_trace_id' => $decision->authorization_trace_id,
            'evaluated_at' => str_replace(' ', 'T', (string) $decision->evaluated_at).(str_ends_with((string) $decision->evaluated_at, 'Z') ? '' : 'Z'),
            'correlation_id' => $correlationId,
            'classification' => $decision->classification,
            'access_context' => is_array($context) ? $context : [
                'subject_id' => $decision->actor_user_id,
                'tenant_id' => $targetFacilityId,
                'clearance' => 'internal',
                'correlation_id' => $correlationId,
            ],
        ];

        return response()->json($payload)->header('X-Correlation-ID', $correlationId);
    }

    /** @param array<string, mixed> $principal */
    private function authorizeWithoutPersistence(array $principal, RecordFacts $facts): bool
    {
        if ($this->access instanceof RbacAbacDecideAccess || $this->access instanceof BootstrapGatedDecideAccess) {
            return $this->access->evaluateOnly($principal, 'authorization.decision.read', $facts)->isAllowed();
        }

        return $this->access->decide($principal, 'authorization.decision.read', $facts)->isAllowed();
    }
}
