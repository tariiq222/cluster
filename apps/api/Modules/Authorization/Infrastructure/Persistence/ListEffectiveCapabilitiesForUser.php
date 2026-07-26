<?php

namespace Modules\Authorization\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Features\OperationsOffice\OperationsOfficeRoleCatalog;

/**
 * Read-side query for the capability codes a user currently holds, through an
 * active role assignment or an active delegation.
 *
 * This is deliberately coarser than DecideAccess:
 * it answers "does this principal hold this capability at all", not "may this
 * principal act on this record". The shell uses it to decide which navigation
 * entries to render, so a user is not offered a screen that answers 403 on
 * arrival. Every endpoint still runs the full record-scoped decision, so a stale
 * or over-broad entry here can only cost a wasted click, never access.
 *
 * Coarse-grained subtraction of active explicit denies is intentional: this
 * resolver has no RecordFacts, so it only matches record-agnostic denies
 * (user_id exact, classification IS NULL, organization_unit_id IS NULL,
 * resource_pattern NULL or wildcard). It also skips subtraction when the
 * user is a platform owner, mirroring the engine's documented exception
 * (PlatformOwnerRoleTest::test_platform_owner_bypasses_explicit_denies).
 * The per-record decision in DecideAccess remains the authoritative gate;
 * this filter only keeps the shell-rendered navigation list honest about
 * user-targeted blocks without hiding screens the owner can still reach.
 */
final class ListEffectiveCapabilitiesForUser
{
    /** @return list<string> */
    public function forUser(string $userId): array
    {
        $now = now()->utc();

        $roleGranted = DB::table('role_assignments')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->join('role_capabilities', 'role_capabilities.role_id', '=', 'roles.id')
            ->join('capabilities', 'capabilities.id', '=', 'role_capabilities.capability_id')
            ->where('role_assignments.user_id', $userId)
            ->where('role_assignments.status', 'active')
            ->where('roles.status', 'active')
            ->where('role_capabilities.effect', 'allow')
            ->where('capabilities.status', 'active')
            ->where('role_assignments.start_at', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('role_assignments.end_at')
                    ->orWhere('role_assignments.end_at', '>', $now);
            })
            ->pluck('capabilities.capability_code');

        $delegationGranted = DB::table('delegations')
            ->join('delegation_capabilities', 'delegation_capabilities.delegation_id', '=', 'delegations.id')
            ->join('capabilities', 'capabilities.capability_code', '=', 'delegation_capabilities.capability_code')
            ->where('delegations.delegate_user_id', $userId)
            ->where('delegations.status', 'active')
            ->where('capabilities.status', 'active')
            ->where('delegations.start_at', '<=', $now)
            ->where('delegations.end_at', '>', $now)
            ->pluck('capabilities.capability_code');

        $codes = [];
        foreach ([$roleGranted, $delegationGranted] as $granted) {
            foreach ($granted as $code) {
                if (is_string($code) && $code !== '') {
                    $codes[$code] = true;
                }
            }
        }

        $codes = array_keys($codes);
        sort($codes);

        if (! $this->userIsPlatformOwner($userId, $now)) {
            $deniedCodes = DB::table('explicit_denies')
                ->where('user_id', $userId)
                ->whereNull('organization_unit_id')
                ->whereNull('classification')
                ->where(function ($query): void {
                    $query->whereNull('resource_pattern')
                        ->orWhere('resource_pattern', '*');
                })
                ->where('issued_at', '<=', $now)
                ->where(function ($query) use ($now): void {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', $now);
                })
                ->pluck('capability_code')
                ->all();

            if ($deniedCodes !== []) {
                $codes = array_values(array_diff($codes, $deniedCodes));
                sort($codes);
            }
        }

        return $codes;
    }

    private function userIsPlatformOwner(string $userId, \DateTimeInterface $now): bool
    {
        return DB::table('role_assignments')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->where('role_assignments.user_id', $userId)
            ->where('role_assignments.status', 'active')
            ->where('role_assignments.start_at', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('role_assignments.end_at')
                    ->orWhere('role_assignments.end_at', '>', $now);
            })
            ->where('roles.status', 'active')
            ->where('roles.code', OperationsOfficeRoleCatalog::PLATFORM_OWNER_ROLE)
            ->exists();
    }
}
