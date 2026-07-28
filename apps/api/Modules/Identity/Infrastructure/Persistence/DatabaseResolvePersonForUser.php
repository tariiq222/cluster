<?php

namespace Modules\Identity\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Identity\Contracts\ResolvePersonForUser;

final class DatabaseResolvePersonForUser implements ResolvePersonForUser
{
    public function forUser(string $userId): ?string
    {
        $personId = DB::table('users')->where('id', $userId)->value('person_id');

        return is_string($personId) && $personId !== '' ? $personId : null;
    }
}
