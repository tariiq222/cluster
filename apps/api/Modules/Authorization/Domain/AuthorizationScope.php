<?php

namespace Modules\Authorization\Domain;

use InvalidArgumentException;
use Modules\Authorization\Contracts\RecordFacts;

final readonly class AuthorizationScope
{
    public const CLUSTER = 'cluster';

    public const FACILITY = 'facility';

    public const UNIT = 'unit';

    public const RECORD_SET = 'record_set';

    /** @var list<string> */
    public const TYPES = [
        self::CLUSTER,
        self::FACILITY,
        self::UNIT,
        self::RECORD_SET,
    ];

    private function __construct(
        public string $scopeType,
        public string $scopeId,
    ) {}

    public static function fromAssignment(string $scopeType, string $scopeId): self
    {
        if (! in_array($scopeType, self::TYPES, true) || trim($scopeId) === '') {
            throw new InvalidArgumentException('Authorization scope is invalid.');
        }

        return new self($scopeType, $scopeId);
    }

    /**
     * Interprets persisted grant rows defensively: legacy rows missing
     * scope_type are read as unit scopes, while rows without usable scope
     * data match nothing (fail closed, no global-scope shortcut).
     */
    public static function fromStorage(?string $scopeType, ?string $scopeId): ?self
    {
        if (! is_string($scopeId) || trim($scopeId) === '') {
            return null;
        }

        $resolvedScopeType = is_string($scopeType) && trim($scopeType) !== ''
            ? $scopeType
            : self::UNIT;

        try {
            return self::fromAssignment($resolvedScopeType, $scopeId);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function covers(RecordFacts $facts): bool
    {
        $candidate = match ($this->scopeType) {
            self::CLUSTER => $facts->clusterId,
            self::FACILITY => $facts->ownerFacilityId,
            self::UNIT => $facts->organizationUnitId,
            self::RECORD_SET => $facts->recordId,
            default => null,
        };

        return is_string($candidate) && $candidate !== '' && hash_equals($this->scopeId, $candidate);
    }
}
