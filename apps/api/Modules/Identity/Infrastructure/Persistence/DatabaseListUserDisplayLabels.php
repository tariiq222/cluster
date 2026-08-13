<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Identity\Contracts\ListUserDisplayLabels;

final class DatabaseListUserDisplayLabels implements ListUserDisplayLabels
{
    /**
     * @param  list<string>  $userIds
     * @return array<string, string>
     */
    public function labelsFor(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(
            $userIds,
            static fn (string $userId): bool => trim($userId) !== '',
        )));
        if ($ids === []) {
            return [];
        }

        $labels = [];
        foreach (DB::table('users')->whereIn('id', $ids)->pluck('display_name_ar', 'id') as $userId => $displayName) {
            $trimmed = trim($displayName);
            if ($trimmed !== '') {
                $labels[(string) $userId] = $trimmed;
            }
        }

        return $labels;
    }
}
