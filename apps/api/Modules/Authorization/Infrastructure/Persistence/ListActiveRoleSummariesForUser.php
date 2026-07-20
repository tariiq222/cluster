<?php

namespace Modules\Authorization\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Read-side query for the active role summaries of a user. Authorization owns
 * the roles/assignments tables; this is the only supported read path for
 * principal-context projections.
 */
final class ListActiveRoleSummariesForUser
{
    /**
     * @return array{roles: list<array{code: string, name_ar: string, name_en: ?string, scope_type: string, scope_id: string, end_at: ?string}>, clearance: string}
     */
    public function forUser(string $userId): array
    {
        $now = now()->utc();

        $rows = DB::table('role_assignments')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->where('role_assignments.user_id', $userId)
            ->where('role_assignments.status', 'active')
            ->where('roles.status', 'active')
            ->where('role_assignments.start_at', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('role_assignments.end_at')
                    ->orWhere('role_assignments.end_at', '>', $now);
            })
            ->select(
                'roles.code',
                'roles.name_ar',
                'roles.name_en',
                'role_assignments.scope_type',
                'role_assignments.scope_id',
                'role_assignments.end_at',
            )
            ->orderBy('roles.code')
            ->get();

        $roles = $rows
            ->map(static function (stdClass $row): ?array {
                if (! is_string($row->code) || ! is_string($row->name_ar) || ! is_string($row->scope_id)) {
                    return null;
                }

                return [
                    'code' => $row->code,
                    'name_ar' => $row->name_ar,
                    'name_en' => is_string($row->name_en) ? $row->name_en : null,
                    'scope_type' => is_string($row->scope_type) ? $row->scope_type : 'unit',
                    'scope_id' => $row->scope_id,
                    'end_at' => is_string($row->end_at) ? $row->end_at : null,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'roles' => $roles,
            'clearance' => $this->clearanceForUser($userId, $now),
        ];
    }

    private function clearanceForUser(string $userId, \DateTimeInterface $now): string
    {
        $highest = DB::table('role_assignments')
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
            ->pluck('capabilities.sensitivity');

        $clearance = 'internal';
        foreach ($highest as $sensitivity) {
            if ($sensitivity === 'critical') {
                return 'top_secret';
            }
            if ($sensitivity === 'sensitive') {
                $clearance = 'confidential';
            }
        }

        return $clearance;
    }
}
