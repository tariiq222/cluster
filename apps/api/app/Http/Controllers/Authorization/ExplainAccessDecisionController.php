<?php

namespace App\Http\Controllers\Authorization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Http\AuthorizationApi;
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
        if (! $this->access->decide($principal, 'identity.account.read', new RecordFacts(
            ownerFacilityId: null,
            resourceType: 'authorization_access_decision',
            classification: (string) $decision->classification,
            factsVersion: (string) $decision->facts_version,
        ))->isAllowed()) {
            return AuthorizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $context = json_decode((string) $decision->access_context, true);
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
                'tenant_id' => $principal['facility_id'],
                'clearance' => 'internal',
                'correlation_id' => $correlationId,
            ],
        ];

        return response()->json($payload)->header('X-Correlation-ID', $correlationId);
    }
}
