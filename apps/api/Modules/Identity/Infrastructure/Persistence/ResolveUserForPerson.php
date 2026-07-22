<?php

namespace Modules\Identity\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Identity\Contracts\ResolveUserForPerson as ResolveUserForPersonContract;

final class ResolveUserForPerson implements ResolveUserForPersonContract
{
    public function forPerson(string $personId): ?string
    {
        $accountId = DB::table('identity_person_account_claims')
            ->where('person_id', $personId)
            ->value('account_id');

        return is_string($accountId) && $accountId !== '' ? $accountId : null;
    }
}
