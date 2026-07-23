<?php

namespace App\Integrations\Notifications;

use Illuminate\Support\Facades\DB;
use Modules\Notifications\Contracts\ResolveTechnicalAlertRecipients;

final class DatabaseTechnicalAlertRecipientResolver implements ResolveTechnicalAlertRecipients
{
    public function resolve(string $recipientCapability): array
    {
        return DB::table('role_assignments')
            ->join('role_capabilities', 'role_capabilities.role_id', '=', 'role_assignments.role_id')
            ->join('capabilities', 'capabilities.id', '=', 'role_capabilities.capability_id')
            ->where('role_assignments.status', 'active')
            ->where('role_assignments.start_at', '<=', now())
            ->where('role_capabilities.effect', 'allow')
            ->where('capabilities.status', 'active')
            ->where('capabilities.capability_code', $recipientCapability)
            ->where(static fn ($query) => $query->whereNull('role_assignments.end_at')->orWhere('role_assignments.end_at', '>', now()))
            ->distinct()
            ->pluck('role_assignments.user_id')
            ->filter(static fn (mixed $id): bool => is_string($id))
            ->values()
            ->all();
    }
}
