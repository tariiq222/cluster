<?php

namespace App\Http\Authentication;

use App\Http\Middleware\IdentityRequestAttributes;
use Illuminate\Http\Request;
use LogicException;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

final class SessionPrincipalResolver implements ResolveDevelopmentFixturePrincipal
{
    public function issue(array $principal): array
    {
        throw new LogicException('Production session principals cannot issue development bearer tokens.');
    }

    public function resolve(Request $request): ?array
    {
        $principal = $request->attributes->get(IdentityRequestAttributes::PRINCIPAL);
        $session = $request->attributes->get(IdentityRequestAttributes::SESSION);
        $organizationUnitId = config('identity.authorization.default_organization_unit_id');
        if (! is_array($principal)
            || ! is_string($principal['user_id'] ?? null)
            || ! is_array($session)
            || ($session['restricted'] ?? true) === true
            || ! is_string($organizationUnitId)
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $organizationUnitId) !== 1) {
            return null;
        }

        return [
            'user_id' => $principal['user_id'],
            'facility_id' => $organizationUnitId,
        ];
    }
}
