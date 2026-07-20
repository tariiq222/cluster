<?php

namespace Modules\Identity\Tests;

use App\Http\Authentication\SessionPrincipalResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Contracts\PrincipalContext;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Modules\Identity\Infrastructure\SessionPrincipalContextResolver;
use Modules\Organization\Contracts\ResolvePersonOrganizationScope;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class PrincipalContextResolverTest extends TestCase
{
    use RefreshDatabase;

    private const USER_ID = '018f6f7d-0c00-7000-8000-000000000501';

    private const SESSION_ID = '018f6f7d-0c00-7000-8000-000000000502';

    private const PERSON_ID = '018f6f7d-0c00-7000-8000-000000000503';

    private const FALLBACK_UNIT_ID = '018f6f7d-0c00-7000-8000-000000000504';

    public function test_missing_session_attribute_resolves_null(): void
    {
        $resolver = $this->resolver($this->fakeScope());

        $this->assertNull($resolver->resolve(Request::create('/api/v1/me', 'GET')));
        $this->assertNull($resolver->resolveSelectedScope(Request::create('/api/v1/me', 'GET')));
    }

    public function test_restricted_session_resolves_null(): void
    {
        $this->seedAccount();
        $this->seedSession();
        $resolver = $this->resolver($this->fakeScope());

        $this->assertNull($resolver->resolve($this->requestWithSession(restricted: true)));
    }

    public function test_active_account_with_person_scope_builds_the_full_context(): void
    {
        $this->seedAccount();
        $this->seedSession(json_encode([
            'ip_cidr' => '10.20.30.0/24',
            'selected_scope' => ['scope_type' => 'facility', 'scope_id' => 'facility-1'],
        ], JSON_THROW_ON_ERROR));
        $scope = $this->fakeScope();
        $resolver = $this->resolver($scope);

        $context = $resolver->resolve($this->requestWithSession());

        $this->assertInstanceOf(PrincipalContext::class, $context);
        $this->assertSame(self::USER_ID, $context->userId);
        $this->assertSame(self::PERSON_ID, $context->personId);
        $this->assertSame('active', $context->accountStatus);
        $this->assertSame(['cluster-1'], $context->clusterIds);
        $this->assertSame(['facility-1', 'facility-2'], $context->facilityIds);
        $this->assertSame(['unit-1'], $context->organizationUnitIds);
        $this->assertSame('unit-1', $context->primaryOrganizationUnitId);
        $this->assertSame(['scope_type' => 'facility', 'scope_id' => 'facility-1'], $context->selectedScope);
        $this->assertFalse($context->sessionRestricted);
        $this->assertSame(self::PERSON_ID, $scope->requestedPersonId);
        $this->assertSame(
            ['scope_type' => 'facility', 'scope_id' => 'facility-1'],
            $resolver->resolveSelectedScope($this->requestWithSession()),
        );
    }

    public function test_disabled_account_resolves_null(): void
    {
        $this->seedAccount('disabled');
        $this->seedSession();
        $resolver = $this->resolver($this->fakeScope());

        $this->assertNull($resolver->resolve($this->requestWithSession()));
    }

    public function test_organization_scope_failure_resolves_null(): void
    {
        $this->seedAccount();
        $this->seedSession();
        $scope = $this->fakeScope();
        $scope->failure = new RuntimeException('organization read model unavailable');
        $resolver = $this->resolver($scope);

        $this->assertNull($resolver->resolve($this->requestWithSession()));
    }

    public function test_actor_and_legacy_arrays_expose_the_authorization_shapes(): void
    {
        $this->seedAccount();
        $this->seedSession();
        $context = $this->resolver($this->fakeScope())->resolve($this->requestWithSession());

        $this->assertInstanceOf(PrincipalContext::class, $context);
        $this->assertSame([
            'user_id' => self::USER_ID,
            'facility_id' => 'unit-1',
            'cluster_ids' => ['cluster-1'],
            'facility_ids' => ['facility-1', 'facility-2'],
            'organization_unit_ids' => ['unit-1'],
            'correlation_id' => '018f6f7d-0c00-7000-8000-000000000505',
        ], $context->toActorArray('018f6f7d-0c00-7000-8000-000000000505'));
        $this->assertNull($context->toActorArray()['correlation_id']);
        $this->assertSame(['user_id' => self::USER_ID, 'facility_id' => 'unit-1'], $context->toLegacyArray());
    }

    public function test_persist_selected_scope_merges_metadata_without_dropping_existing_keys(): void
    {
        $this->seedAccount();
        $this->seedSession(json_encode([
            'ip_cidr' => '10.20.30.0/24',
            'user_agent_hash' => hash('sha256', 'principal-context-agent'),
        ], JSON_THROW_ON_ERROR));
        $resolver = $this->resolver($this->fakeScope());
        $request = $this->requestWithSession();

        $resolver->persistSelectedScope($request, 'organization_unit', 'unit-9');

        $metadata = json_decode((string) DB::table('identity_sessions')->where('id', self::SESSION_ID)->value('metadata'), true);
        $this->assertSame('10.20.30.0/24', $metadata['ip_cidr']);
        $this->assertSame(hash('sha256', 'principal-context-agent'), $metadata['user_agent_hash']);
        $this->assertSame(['scope_type' => 'organization_unit', 'scope_id' => 'unit-9'], $metadata['selected_scope']);
        $this->assertSame(
            ['scope_type' => 'organization_unit', 'scope_id' => 'unit-9'],
            $resolver->resolveSelectedScope($request),
        );
    }

    public function test_legacy_resolver_returns_user_and_facility_keys_from_the_trusted_context(): void
    {
        $this->seedAccount();
        $this->seedSession();
        $resolver = $this->resolver($this->fakeScope());
        $this->app->instance(ResolvePrincipalContext::class, $resolver);
        $legacy = $this->app->make(SessionPrincipalResolver::class);
        $request = $this->requestWithSession();

        $this->assertSame(
            ['user_id' => self::USER_ID, 'facility_id' => 'unit-1'],
            $legacy->resolve($request),
        );
        $this->assertInstanceOf(PrincipalContext::class, $legacy->principalContext($request));
    }

    public function test_legacy_resolver_falls_back_to_the_configured_unit_only_without_resolvable_scope(): void
    {
        $this->seedAccount();
        $this->seedSession();
        config(['identity.authorization.default_organization_unit_id' => self::FALLBACK_UNIT_ID]);
        $scope = $this->fakeScope();
        $scope->scope = [
            'cluster_ids' => [],
            'facility_ids' => [],
            'organization_unit_ids' => [],
            'primary_organization_unit_id' => null,
        ];
        $this->app->instance(ResolvePrincipalContext::class, $this->resolver($scope));
        $legacy = $this->app->make(SessionPrincipalResolver::class);

        $this->assertSame(
            ['user_id' => self::USER_ID, 'facility_id' => self::FALLBACK_UNIT_ID],
            $legacy->resolve($this->requestWithSession()),
        );
    }

    private function resolver(ResolvePersonOrganizationScope $scope): SessionPrincipalContextResolver
    {
        $this->app->instance(ResolvePersonOrganizationScope::class, $scope);

        return $this->app->make(SessionPrincipalContextResolver::class);
    }

    private function fakeScope(): FakePersonOrganizationScope
    {
        return new FakePersonOrganizationScope;
    }

    private function requestWithSession(bool $restricted = false): Request
    {
        $request = Request::create('/api/v1/me', 'GET');
        $request->attributes->set('identity.session', [
            'user_id' => self::USER_ID,
            'session_id' => self::SESSION_ID,
            'csrf_token_hash' => null,
            'restricted' => $restricted,
        ]);
        $request->attributes->set('identity.principal', ['user_id' => self::USER_ID]);

        return $request;
    }

    private function seedAccount(string $status = 'active'): void
    {
        DB::table('users')->insert([
            'id' => self::USER_ID,
            'username' => 'principal.context',
            'person_id' => self::PERSON_ID,
            'person_version' => 1,
            'display_name_ar' => 'اختبار السياق',
            'display_name_en' => 'Principal Context',
            'status' => $status,
            'must_change_password' => false,
            'password_version' => 1,
            'last_login_at' => null,
            'failed_login_count' => 0,
            'locked_until' => null,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedSession(string $metadata = '{}'): void
    {
        DB::table('identity_sessions')->insert([
            'id' => self::SESSION_ID,
            'user_id' => self::USER_ID,
            'token_hash' => hash('sha256', 'principal-context-session'),
            'password_version' => 1,
            'issued_at' => now(),
            'expires_at' => now()->addHour(),
            'revoked_at' => null,
            'last_seen_at' => null,
            'metadata' => $metadata,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

final class FakePersonOrganizationScope implements ResolvePersonOrganizationScope
{
    /** @var array{cluster_ids: list<string>, facility_ids: list<string>, organization_unit_ids: list<string>, primary_organization_unit_id: ?string} */
    public array $scope = [
        'cluster_ids' => ['cluster-1'],
        'facility_ids' => ['facility-1', 'facility-2'],
        'organization_unit_ids' => ['unit-1'],
        'primary_organization_unit_id' => 'unit-1',
    ];

    public ?Throwable $failure = null;

    public ?string $requestedPersonId = null;

    public function forPerson(string $personId): array
    {
        $this->requestedPersonId = $personId;
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        return $this->scope;
    }
}
