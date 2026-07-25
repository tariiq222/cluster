<?php

namespace App\Http\Authentication;

use App\Http\Middleware\IdentityRequestAttributes;
use Illuminate\Http\Request;
use LogicException;
use Modules\Identity\Contracts\PrincipalContext;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Modules\Organization\Contracts\ResolveDevelopmentFixturePrincipal as OrganizationResolveDevelopmentFixturePrincipal;

final class SessionPrincipalResolver implements OrganizationResolveDevelopmentFixturePrincipal, ResolveDevelopmentFixturePrincipal
{
    public function __construct(private readonly ?ResolvePrincipalContext $principalContexts = null) {}

    public function issue(array $principal): array
    {
        throw new LogicException('Production session principals cannot issue development bearer tokens.');
    }

    public function resolve(Request $request): ?array
    {
        $context = $this->principalContext($request);
        $legacy = $context?->toLegacyArray();
        if ($legacy !== null && is_string($legacy['facility_id'])) {
            return [
                ...$legacy,
                'cluster_ids' => $context->clusterIds,
                'facility_ids' => $context->facilityIds,
                'organization_unit_ids' => $context->organizationUnitIds,
            ];
        }

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

    public function principalContext(Request $request): ?PrincipalContext
    {
        return $this->principalContexts?->resolve($request);
    }
}
