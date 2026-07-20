<?php

namespace Modules\Authorization\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use stdClass;

/**
 * Proves at delegation-creation time that the delegator actually holds every
 * delegated capability inside a scope and window that cover the delegation.
 * A delegation may narrow scope or shorten the window; it may never widen
 * either beyond the delegator's own authority.
 */
final class ValidateDelegationAuthority
{
    /** @var array<string, int> */
    private const SCOPE_RANK = ['record_set' => 0, 'unit' => 1, 'facility' => 2, 'cluster' => 3];

    public function __construct(private readonly ResolveOrganizationScopeAncestry $ancestry) {}

    /**
     * @param  list<string>  $capabilityCodes
     *
     * @throws InvalidArgumentException delegation_exceeds_delegator_authority
     */
    public function assertCovered(
        string $delegatorUserId,
        array $capabilityCodes,
        string $scopeType,
        string $scopeId,
        string $startAt,
        string $endAt,
    ): void {
        $requestedAncestry = $this->ancestry->ancestry($scopeType, $scopeId);
        if ($requestedAncestry === null) {
            throw new InvalidArgumentException('delegation_exceeds_delegator_authority');
        }

        foreach ($capabilityCodes as $code) {
            $grants = $this->delegatorGrants($delegatorUserId, $code);
            $covered = false;
            foreach ($grants as $grant) {
                if ($this->windowCovers($grant, $startAt, $endAt) && $this->scopeCovers($grant, $scopeType, $scopeId, $requestedAncestry)) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                throw new InvalidArgumentException('delegation_exceeds_delegator_authority');
            }
        }
    }

    /** @return list<stdClass> */
    private function delegatorGrants(string $delegatorUserId, string $capabilityCode): array
    {
        $now = now()->utc();

        return DB::table('role_assignments')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->join('role_capabilities', 'role_capabilities.role_id', '=', 'roles.id')
            ->join('capabilities', 'capabilities.id', '=', 'role_capabilities.capability_id')
            ->where('role_assignments.user_id', $delegatorUserId)
            ->where('role_assignments.status', 'active')
            ->where('roles.status', 'active')
            ->where('role_capabilities.effect', 'allow')
            ->where('capabilities.status', 'active')
            ->where('capabilities.capability_code', $capabilityCode)
            ->where('role_assignments.start_at', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('role_assignments.end_at')
                    ->orWhere('role_assignments.end_at', '>', $now);
            })
            ->select('role_assignments.scope_type', 'role_assignments.scope_id', 'role_assignments.start_at', 'role_assignments.end_at')
            ->get()
            ->all();
    }

    private function windowCovers(stdClass $grant, string $startAt, string $endAt): bool
    {
        $grantStart = $this->timestamp($grant->start_at);
        $grantEnd = $grant->end_at === null ? null : $this->timestamp($grant->end_at);
        $start = $this->timestamp($startAt);
        $end = $this->timestamp($endAt);
        if ($grantStart === null || $start === null || $end === null) {
            return false;
        }

        return $grantStart <= $start && ($grantEnd === null || $grantEnd >= $end);
    }

    /** @param array{cluster_id: ?string, facility_id: ?string, unit_id: ?string} $requestedAncestry */
    private function scopeCovers(stdClass $grant, string $scopeType, string $scopeId, array $requestedAncestry): bool
    {
        $grantType = is_string($grant->scope_type) && $grant->scope_type !== '' ? $grant->scope_type : 'unit';
        $grantScopeId = (string) $grant->scope_id;

        if ($grantType === $scopeType && hash_equals($grantScopeId, $scopeId)) {
            return true;
        }

        $grantRank = self::SCOPE_RANK[$grantType] ?? -1;
        $requestedRank = self::SCOPE_RANK[$scopeType] ?? -1;
        if ($grantRank < 0 || $requestedRank < 0 || $requestedRank >= $grantRank) {
            return false;
        }

        return match ($grantType) {
            'cluster' => is_string($requestedAncestry['cluster_id']) && hash_equals($grantScopeId, $requestedAncestry['cluster_id']),
            'facility' => is_string($requestedAncestry['facility_id']) && hash_equals($grantScopeId, $requestedAncestry['facility_id']),
            'unit' => is_string($requestedAncestry['unit_id']) && hash_equals($grantScopeId, $requestedAncestry['unit_id']),
            default => false,
        };
    }

    private function timestamp(mixed $value): ?int
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $parsed = strtotime($value);

        return $parsed === false ? null : $parsed;
    }
}
