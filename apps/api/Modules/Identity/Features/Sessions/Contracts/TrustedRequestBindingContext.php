<?php

namespace Modules\Identity\Features\Sessions\Contracts;

final readonly class TrustedRequestBindingContext
{
    public function __construct(
        public string $ipCidr,
        public string $userAgentHash,
        public bool $trusted = true,
    ) {}

    public function isUsable(): bool
    {
        return $this->trusted
            && $this->ipCidr !== ''
            && preg_match('/\A[0-9a-f]{64}\z/', $this->userAgentHash) === 1;
    }
}
