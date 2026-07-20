<?php

namespace Modules\Authorization\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use stdClass;

/**
 * Proves that an administrative actor may only grant authority they hold for
 * the complete requested organizational scope and time window.
 */
final class ValidateGrantAuthority
{
    /** @var array<string, int> */
    private const SCOPE_RANK = ['record_set' => 0, 'unit' => 1, 'facility' => 2, 'cluster' => 3];

    public function __construct(private readonly ResolveOrganizationScopeAncestry $ancestry) {}

    /** @param list<string> $capabilityCodes */
    public function assertCovered(
        string $actorUserId,
        array $capabilityCodes,
        string $scopeType,
        string $scopeId,
        string $startAt,
        ?string $endAt,
        bool $allowAdministrativeAuthority = true,
    ): void {
        if ($capabilityCodes === []) {
            throw new InvalidArgumentException('authorization_grant_exceeds_actor_authority');
        }
        $requestedAncestry = $this->ancestry->ancestry($scopeType, $scopeId);
        if ($requestedAncestry === null) {
            throw new InvalidArgumentException('authorization_grant_exceeds_actor_authority');
        }
        foreach (array_unique($capabilityCodes) as $code) {
            $covered = false;
            foreach ($this->actorGrants($actorUserId, $code) as $grant) {
                if ($this->windowCovers($grant, $startAt, $endAt)
                    && $this->scopeCovers($grant, $scopeType, $scopeId, $requestedAncestry)) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered && $allowAdministrativeAuthority && $this->hasAdministrativeGrantAuthority($actorUserId, $scopeType, $scopeId, $startAt, $endAt, $requestedAncestry)) {
                foreach ($this->actorGrants($actorUserId, 'authorization.assignment.manage') as $grant) {
                    if ($this->windowCovers($grant, $startAt, $endAt)
                        && $this->scopeCovers($grant, $scopeType, $scopeId, $requestedAncestry)) {
                        $covered = true;
                        break;
                    }
                }
            }
            if (! $covered) {
                throw new InvalidArgumentException('authorization_grant_exceeds_actor_authority');
            }
        }
    }

    /** @param list<array{scope_type:string,scope_id:string}> $requestedAncestry */
    private function hasAdministrativeGrantAuthority(
        string $actorUserId,
        string $scopeType,
        string $scopeId,
        string $startAt,
        ?string $endAt,
        array $requestedAncestry,
    ): bool {
        foreach ($this->actorGrants($actorUserId, 'authorization.assignment.manage') as $grant) {
            if ($this->windowCovers($grant, $startAt, $endAt)
                && $this->scopeCovers($grant, $scopeType, $scopeId, $requestedAncestry)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<stdClass> */
    private function actorGrants(string $actorUserId, string $capabilityCode): array
    {
        return DB::table('role_assignments')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->join('role_capabilities', 'role_capabilities.role_id', '=', 'roles.id')
            ->join('capabilities', 'capabilities.id', '=', 'role_capabilities.capability_id')
            ->where('role_assignments.user_id', $actorUserId)
            ->where('role_assignments.status', 'active')
            ->where('roles.status', 'active')
            ->where('role_capabilities.effect', 'allow')
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('role_capabilities as denied_role_capabilities')
                    ->whereColumn('denied_role_capabilities.role_id', 'roles.id')
                    ->whereColumn('denied_role_capabilities.capability_id', 'capabilities.id')
                    ->where('denied_role_capabilities.effect', 'deny');
            })
            ->where('capabilities.status', 'active')
            ->where('capabilities.capability_code', $capabilityCode)
            ->select('role_assignments.scope_type', 'role_assignments.scope_id', 'role_assignments.start_at', 'role_assignments.end_at')
            ->get()->all();
    }

    private function windowCovers(stdClass $grant, string $startAt, ?string $endAt): bool
    {
        $grantStart = $this->timestamp($grant->start_at);
        $grantEnd = $grant->end_at === null ? null : $this->timestamp($grant->end_at);
        $start = $this->timestamp($startAt);
        $end = $endAt === null ? null : $this->timestamp($endAt);
        if ($grantStart === null || $start === null || ($endAt !== null && $end === null)) {
            return false;
        }

        return $grantStart <= $start
            && ($end === null ? $grantEnd === null : ($grantEnd === null || $grantEnd >= $end));
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
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (! is_string($value) || $value === '') {
            return null;
        }
        foreach (['!Y-m-d\TH:i:s.v\Z', '!Y-m-d H:i:s.v', '!Y-m-d H:i:s'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            if ($parsed !== false) {
                return $parsed->getTimestamp();
            }
        }

        return null;
    }
}
