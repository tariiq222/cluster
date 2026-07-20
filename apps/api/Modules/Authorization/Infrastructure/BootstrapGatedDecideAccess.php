<?php

namespace Modules\Authorization\Infrastructure;

use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Infrastructure\Persistence\AuthorizationBootstrapState;

final class BootstrapGatedDecideAccess implements DecideAccess
{
    /** @var list<string> */
    public const SETUP_CAPABILITIES = [
        'organization.bootstrap',
        'identity.bootstrap',
        'authorization.bootstrap.complete',
    ];

    public function __construct(
        private readonly RbacAbacDecideAccess $engine,
        private readonly AuthorizationBootstrapState $bootstrap,
    ) {}

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        if ($this->bootstrap->isPending() && ! in_array($capability, self::SETUP_CAPABILITIES, true)) {
            return $this->pendingDecision($capability, $facts);
        }

        return $this->engine->decide($actor, $capability, $facts);
    }

    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        if ($this->bootstrap->isPending() && ! in_array($capability, self::SETUP_CAPABILITIES, true)) {
            return $this->pendingDecision($capability, $facts);
        }

        return $this->engine->evaluateOnly($actor, $capability, $facts);
    }

    public function usesProductionEngine(): bool
    {
        return $this->engine instanceof RbacAbacDecideAccess;
    }

    private function pendingDecision(string $capability, ?RecordFacts $facts): AccessDecision
    {
        return new AccessDecision(
            decision: 'deny',
            action: $capability,
            resourceType: $facts?->resourceType ?? 'unknown',
            reasonCodes: ['authorization_bootstrap_pending'],
            policyVersion: 'bootstrap-gate-v1',
            factsVersion: $facts?->factsVersion ?? 'unavailable',
            classification: $facts?->classification ?? 'unknown',
        );
    }
}
