<?php

namespace Modules\Identity\Features\Authentication\Contracts;

use DateTimeImmutable;

final readonly class PreAuthThrottleDecision
{
    public function __construct(
        public bool $allowed,
        public string $scope = 'none',
        public ?DateTimeImmutable $blockedUntil = null,
        public int $lockLevel = 0,
    ) {}
}
