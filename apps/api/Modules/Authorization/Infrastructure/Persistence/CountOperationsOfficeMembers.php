<?php

namespace Modules\Authorization\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\CountOperationsOfficeMembers as CountOperationsOfficeMembersContract;
use Modules\Authorization\Features\OperationsOffice\OperationsOfficeRoleCatalog;

final class CountOperationsOfficeMembers implements CountOperationsOfficeMembersContract
{
    public function activeMembers(): int
    {
        $now = now()->utc();

        return (int) DB::table('role_assignments')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->where('roles.code', OperationsOfficeRoleCatalog::OFFICE_MEMBER_ROLE)
            ->where('roles.status', 'active')
            ->where('role_assignments.status', 'active')
            ->where('role_assignments.start_at', '<=', $now)
            ->where(static fn ($query) => $query
                ->whereNull('role_assignments.end_at')
                ->orWhere('role_assignments.end_at', '>', $now))
            ->distinct()
            ->count('role_assignments.user_id');
    }
}
