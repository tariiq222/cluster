<?php

namespace Modules\Authorization\Features\OperationsOffice;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class OperationsOfficeMembershipResolver
{
    public function activeMemberCount(string $clusterId): int
    {
        return $this->activeAssignments($clusterId)
            ->distinct()
            ->count('role_assignments.user_id');
    }

    public function isActiveMember(string $userId, string $clusterId): bool
    {
        return $this->activeAssignments($clusterId)
            ->where('role_assignments.user_id', $userId)
            ->exists();
    }

    /** @return list<string> */
    public function activeMemberUserIds(string $clusterId): array
    {
        return $this->activeAssignments($clusterId)
            ->distinct()
            ->orderBy('role_assignments.user_id')
            ->pluck('role_assignments.user_id')
            ->map(static fn (mixed $userId): string => (string) $userId)
            ->all();
    }

    private function activeAssignments(string $clusterId): Builder
    {
        $now = now()->utc();

        return DB::table('role_assignments')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->where('roles.code', OperationsOfficeRoleCatalog::OFFICE_MEMBER_ROLE)
            ->where('roles.status', 'active')
            ->where('role_assignments.scope_type', 'cluster')
            ->where('role_assignments.scope_id', $clusterId)
            ->where('role_assignments.status', 'active')
            ->where('role_assignments.start_at', '<=', $now)
            ->where(static fn (Builder $query): Builder => $query
                ->whereNull('role_assignments.end_at')
                ->orWhere('role_assignments.end_at', '>', $now));
    }
}
