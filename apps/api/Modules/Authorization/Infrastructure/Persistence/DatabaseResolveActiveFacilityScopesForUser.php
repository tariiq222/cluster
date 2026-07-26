<?php

namespace Modules\Authorization\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\ResolveActiveFacilityScopesForUser;

final class DatabaseResolveActiveFacilityScopesForUser implements ResolveActiveFacilityScopesForUser
{
    /** @return list<string> */
    public function facilityScopeIds(string $userId, ?string $atIso8601 = null): array
    {
        $at = $atIso8601 === null
            ? now()->utc()
            : DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $atIso8601, new DateTimeZone('UTC'));
        if ($at === false) {
            $at = now()->utc();
        }

        return DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where('scope_type', 'facility')
            ->where('status', 'active')
            ->where('start_at', '<=', $at)
            ->where(fn ($query) => $query->whereNull('end_at')->orWhere('end_at', '>', $at))
            ->pluck('scope_id')
            ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();
    }
}
