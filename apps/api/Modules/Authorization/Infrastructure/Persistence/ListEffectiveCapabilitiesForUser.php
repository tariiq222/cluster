<?php

namespace Modules\Authorization\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\DecideAccess;

/**
 * Read-side query for the capability codes a user currently holds, through an
 * active role assignment or an active delegation.
 *
 * This is deliberately coarser than {@see DecideAccess}:
 * it answers "does this principal hold this capability at all", not "may this
 * principal act on this record". The shell uses it to decide which navigation
 * entries to render, so a user is not offered a screen that answers 403 on
 * arrival. Every endpoint still runs the full record-scoped decision, so a stale
 * or over-broad entry here can only cost a wasted click, never access.
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

        return $codes;
    }
}
