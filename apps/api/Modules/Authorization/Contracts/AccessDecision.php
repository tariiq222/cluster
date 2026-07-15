<?php

namespace Modules\Authorization\Contracts;

final readonly class AccessDecision
{
    /**
     * @param list<string> $reasonCodes
     */
    public function __construct(
        public string $decision,
        public string $action,
        public string $resourceType,
        public array $reasonCodes,
        public string $policyVersion,
        public string $factsVersion,
        public string $classification,
    ) {
    }

    public function isAllowed(): bool
    {
        return $this->decision === 'allow';
    }
}
