<?php

namespace Modules\Authorization\Infrastructure\Persistence;

use InvalidArgumentException;

/**
 * Proves at delegation-creation time that the delegator actually holds every
 * delegated capability inside a scope and window that cover the delegation.
 */
final class ValidateDelegationAuthority
{
    public function __construct(private readonly ValidateGrantAuthority $grantAuthority) {}

    /** @param list<string> $capabilityCodes */
    public function assertCovered(
        string $delegatorUserId,
        array $capabilityCodes,
        string $scopeType,
        string $scopeId,
        string $startAt,
        string $endAt,
    ): void {
        try {
            $this->grantAuthority->assertCovered($delegatorUserId, $capabilityCodes, $scopeType, $scopeId, $startAt, $endAt, false);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('delegation_exceeds_delegator_authority');
        }
    }
}
