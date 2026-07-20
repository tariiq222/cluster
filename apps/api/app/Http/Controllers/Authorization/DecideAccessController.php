<?php

namespace App\Http\Controllers\Authorization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Contracts\AuthorizationResourceReference;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Contracts\ResolveAuthorizationSimulationFacts;
use Modules\Authorization\Http\AuthorizationApi;
use Modules\Authorization\Infrastructure\BootstrapGatedDecideAccess;
use Modules\Authorization\Infrastructure\RbacAbacDecideAccess;
use Modules\Authorization\Infrastructure\Simulation\RegisteredAuthorizationSimulationFactsResolver;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

final class DecideAccessController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly ?ResolveAuthorizationSimulationFacts $factsResolver = null,
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
        $allowedKeys = ['action', 'resource_reference', 'access_context', 'record_facts'];
        $reference = $input['resource_reference'] ?? null;
        if (array_diff(array_keys($input), $allowedKeys) !== []
            || ! is_string($input['action'] ?? null)
            || ! is_array($reference)
            || array_diff(array_keys($reference), ['type', 'id']) !== []
            || ! is_string($reference['type'] ?? null)
            || trim($reference['type']) === ''
            || ! AuthorizationApi::isUuidV7($reference['id'] ?? null)
            || (isset($input['access_context']) && ! is_array($input['access_context']))
            || (isset($input['record_facts']) && ! is_array($input['record_facts']))) {
            return AuthorizationApi::problem(422, 'invalid-access-decision', 'Unprocessable Entity', 'The access decision payload is invalid.', $correlationId);
        }

        $facts = ($this->factsResolver ?? new RegisteredAuthorizationSimulationFactsResolver)
            ->resolve(new AuthorizationResourceReference($reference['type'], $reference['id']));
        if ($facts === null) {
            return AuthorizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $trustedPrincipal = [...$principal, 'correlation_id' => $correlationId];
        if (! $this->authorizeWithoutPersistence($trustedPrincipal, $facts)) {
            return AuthorizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $decision = $this->access->decide($trustedPrincipal, $input['action'], $facts);
        if ($decision->decisionId === null) {
            return AuthorizationApi::problem(500, 'authorization-write-failed', 'Internal Server Error', 'The access decision could not be recorded safely.', $correlationId);
        }

        $payload = [
            'decision_id' => $decision->decisionId,
            'decision' => $decision->decision,
            'action' => $decision->action,
            'resource_type' => $decision->resourceType,
            'resource_id' => $facts->recordId,
            'reason_codes' => array_values(array_unique($decision->reasonCodes)),
            'policy_version' => $decision->policyVersion,
            'facts_version' => $decision->factsVersion,
            'correlation_id' => $correlationId,
            'classification' => $decision->classification,
            'access_context' => [
                'subject_id' => $principal['user_id'],
                'tenant_id' => $principal['facility_id'],
                'organization_unit_ids' => is_array($principal['organization_unit_ids'] ?? null)
                    ? array_values($principal['organization_unit_ids'])
                    : [],
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
