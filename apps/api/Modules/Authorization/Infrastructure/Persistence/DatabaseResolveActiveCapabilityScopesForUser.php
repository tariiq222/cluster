<?php

namespace Modules\Authorization\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\ResolveActiveCapabilityScopesForUser;

final class DatabaseResolveActiveCapabilityScopesForUser implements ResolveActiveCapabilityScopesForUser
{
    public function roots(string $userId, string $capability): array
    {
        $now = now()->utc();

        $roleRoots = DB::table('role_assignments')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->join('role_capabilities', 'role_capabilities.role_id', '=', 'roles.id')
            ->join('capabilities', 'capabilities.id', '=', 'role_capabilities.capability_id')
            ->where('role_assignments.user_id', $userId)
            ->where('role_assignments.status', 'active')
            ->where('role_assignments.start_at', '<=', $now)
            ->where(fn ($query) => $query->whereNull('role_assignments.end_at')->orWhere('role_assignments.end_at', '>', $now))
            ->where('roles.status', 'active')
            ->where('role_capabilities.effect', 'allow')
            ->where('capabilities.status', 'active')
            ->where('capabilities.capability_code', $capability)
            ->get(['role_assignments.scope_type', 'role_assignments.scope_id']);

        $delegationRoots = DB::table('delegations')
            ->join('delegation_capabilities', 'delegation_capabilities.delegation_id', '=', 'delegations.id')
            ->join('capabilities', 'capabilities.capability_code', '=', 'delegation_capabilities.capability_code')
            ->where('delegations.delegate_user_id', $userId)
            ->where('delegations.status', 'active')
            ->where('delegations.start_at', '<=', $now)
            ->where('delegations.end_at', '>', $now)
            ->where('delegation_capabilities.capability_code', $capability)
            ->where('capabilities.status', 'active')
            ->get(['delegations.scope_type', 'delegations.scope_id']);

        $roots = [];
        foreach ($roleRoots->concat($delegationRoots) as $row) {
            $scopeType = $row->scope_type ?? null;
            $scopeId = $row->scope_id ?? null;
            if (! is_string($scopeType) || ! in_array($scopeType, ['cluster', 'facility', 'unit'], true)
                || ! is_string($scopeId) || trim($scopeId) === '') {
                continue;
            }

            $roots[$scopeType.'|'.$scopeId] = [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
            ];
        }

        $order = ['cluster' => 0, 'facility' => 1, 'unit' => 2];
        $roots = array_values($roots);
        usort($roots, static fn (array $left, array $right): int => [
            $order[$left['scope_type']],
            $left['scope_id'],
        ] <=> [
            $order[$right['scope_type']],
            $right['scope_id'],
        ]);

        return $roots;
    }
}
