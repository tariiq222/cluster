<?php

namespace Modules\Organization\Contracts;

/**
 * Capability decision surface consumed by Organization HTTP controllers.
 * Mirrors Modules\Authorization\Contracts\AccessDecision but is owned by the
 * Organization module so lower-ranked controllers never reach across ranks.
 */
final readonly class AccessDecision
{
    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $allowedActions
     * @param  array<string, 'hidden'|'masked'|'readonly'|'editable'>  $fieldAccess
     * @param  list<string>  $obligations
     */
    public function __construct(
        public string $decision,
        public string $action,
        public string $resourceType,
        public array $reasonCodes,
        public string $policyVersion,
        public string $factsVersion,
        public string $classification,
        public ?string $decisionId = null,
        public array $allowedActions = [],
        public array $fieldAccess = [],
        public array $obligations = [],
    ) {}

    public function isAllowed(): bool
    {
        return $this->decision === 'allow';
    }
}