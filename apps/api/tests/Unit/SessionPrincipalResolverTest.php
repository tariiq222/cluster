<?php

namespace Tests\Unit;

use App\Http\Authentication\SessionPrincipalResolver;
use App\Http\Middleware\IdentityRequestAttributes;
use Illuminate\Http\Request;
use Tests\TestCase;

final class SessionPrincipalResolverTest extends TestCase
{
    public function test_it_resolves_only_an_unrestricted_authenticated_session(): void
    {
        config()->set('identity.authorization.default_organization_unit_id', '018f6f7d-0c00-7000-8000-000000000011');
        $request = Request::create('/api/v1/documents/uploads', 'POST');
        $request->attributes->set(IdentityRequestAttributes::PRINCIPAL, [
            'user_id' => '018f6f7d-0c00-7000-8000-000000000021',
        ]);
        $request->attributes->set(IdentityRequestAttributes::SESSION, [
            'restricted' => false,
        ]);

        $this->assertSame([
            'user_id' => '018f6f7d-0c00-7000-8000-000000000021',
            'facility_id' => '018f6f7d-0c00-7000-8000-000000000011',
        ], (new SessionPrincipalResolver)->resolve($request));

        $request->attributes->set(IdentityRequestAttributes::SESSION, ['restricted' => true]);
        $this->assertNull((new SessionPrincipalResolver)->resolve($request));
    }
}
