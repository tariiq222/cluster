<?php

namespace Modules\Identity\Contracts;

use Illuminate\Http\Request;

interface ResolveDevelopmentFixturePrincipal
{
    /**
     * @param  array{user_id: string, facility_id: string}  $principal
     * @return array{access_token: string, expires_at: string}
     */
    public function issue(array $principal): array;

    /** @return array{user_id: string, facility_id: string}|null */
    public function resolve(Request $request): ?array;
}
