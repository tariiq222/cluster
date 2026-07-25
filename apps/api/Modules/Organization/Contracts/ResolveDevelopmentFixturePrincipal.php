<?php

namespace Modules\Organization\Contracts;

use Illuminate\Http\Request;

/**
 * Resolves the principal for Organization HTTP controllers. Mirrors
 * Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal but is owned by
 * the Organization module so the lower-ranked controllers do not need to import
 * any higher-ranked module directly. The Identity module provides the
 * implementation.
 */
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