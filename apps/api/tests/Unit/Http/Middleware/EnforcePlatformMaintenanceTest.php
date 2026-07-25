<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\EnforcePlatformMaintenance;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\PrincipalContext;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Tests\TestCase;

/**
 * EnforcePlatformMaintenance is the api/v1 maintenance-window guard.
 * Stage 3 caches the active-window state under platform:maintenance:active
 * for 60 seconds; DecideAccess is only invoked when a window is active,
 * and the per-principal call is never cached.
 *
 * PrincipalContext is a final readonly class with public readonly
 * properties, so we instantiate it directly rather than mocking it.
 */
final class EnforcePlatformMaintenanceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000011';
    private const ORG_UNIT_ID = '018f6f7d-0c00-7000-8000-000000000012';
    private const USER_ID = '018f6f7d-0c00-7000-8000-000000000021';
    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000501';
    private const CACHE_KEY = 'platform:maintenance:active';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(self::CACHE_KEY);
    }

    public function test_inactive_maintenance_window_passes_through_and_never_consults_principals_or_decide_access(): void
    {
        $this->seedCache(['active' => false]);

        $principals = Mockery::mock(ResolvePrincipalContext::class);
        $principals->shouldNotReceive('resolve');
        $this->app->instance(ResolvePrincipalContext::class, $principals);

        $access = Mockery::mock(DecideAccess::class);
        $access->shouldNotReceive('decide');
        $this->app->instance(DecideAccess::class, $access);

        $response = $this->app->make(EnforcePlatformMaintenance::class)
            ->handle($this->buildProtectedRequest(), fn (Request $passed): mixed => response('next-called', 200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('next-called', $response->getContent());
    }

    public function test_inactive_window_passes_request_through_even_without_principal(): void
    {
        $this->seedCache(['active' => false]);
        $this->app->instance(ResolvePrincipalContext::class, $this->principalsReturning(null));
        $this->app->instance(DecideAccess::class, $this->accessNeverCalled());

        $expected = response('next-called-without-principal', 202);
        $response = $this->app->make(EnforcePlatformMaintenance::class)
            ->handle($this->buildProtectedRequest(), fn (Request $passed): mixed => $expected);

        $this->assertSame($expected, $response);
    }

    public function test_active_maintenance_window_blocks_non_admin_principal_with_503(): void
    {
        $this->seedCache($this->activeWindowShape());

        $access = Mockery::mock(DecideAccess::class);
        $access->shouldReceive('decide')
            ->once()
            ->andReturn(new AccessDecision(
                decision: 'deny',
                action: 'manage',
                resourceType: 'platform_maintenance',
                reasonCodes: ['capability_missing'],
                policyVersion: 'test-v1',
                factsVersion: 'test-v1',
                classification: 'internal',
            ));
        $this->app->instance(DecideAccess::class, $access);
        $this->app->instance(ResolvePrincipalContext::class, $this->principalsReturning($this->buildPrincipal()));

        $response = $this->app->make(EnforcePlatformMaintenance::class)
            ->handle($this->buildProtectedRequest(), fn (Request $passed): mixed => response('next-called', 200));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_active_maintenance_window_admits_admin_principal_and_invokes_decide_access_once(): void
    {
        $this->seedCache($this->activeWindowShape());

        $access = Mockery::mock(DecideAccess::class);
        $access->shouldReceive('decide')
            ->once()
            ->withArgs(function (array $actor, string $capability, $facts): bool {
                $this->assertSame(self::USER_ID, $actor['user_id'] ?? null);
                $this->assertSame(self::FACILITY_ID, $actor['facility_id'] ?? null);
                $this->assertSame(self::CORRELATION_ID, $actor['correlation_id'] ?? null);
                $this->assertSame('platform_operations.maintenance.manage', $capability);
                $this->assertInstanceOf(RecordFacts::class, $facts);
                $this->assertSame('platform_maintenance', $facts->resourceType);

                return true;
            })
            ->andReturn(new AccessDecision(
                decision: 'allow',
                action: 'manage',
                resourceType: 'platform_maintenance',
                reasonCodes: ['capability_grant'],
                policyVersion: 'test-v1',
                factsVersion: 'test-v1',
                classification: 'internal',
            ));
        $this->app->instance(DecideAccess::class, $access);
        $this->app->instance(ResolvePrincipalContext::class, $this->principalsReturning($this->buildPrincipal()));

        $response = $this->app->make(EnforcePlatformMaintenance::class)
            ->handle($this->buildProtectedRequest(), fn (Request $passed): mixed => response('next-called', 200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('next-called', $response->getContent());
    }

    public function test_request_with_custom_correlation_id_is_forwarded_to_decide_access_before_503_problem(): void
    {
        $customCorrelation = '018f6f7d-0c00-7000-8000-000000000599';
        $this->seedCache($this->activeWindowShape());

        $access = Mockery::mock(DecideAccess::class);
        $access->shouldReceive('decide')
            ->once()
            ->withArgs(fn (array $actor): bool => ($actor['correlation_id'] ?? null) === $customCorrelation)
            ->andReturn(new AccessDecision(
                decision: 'deny',
                action: 'manage',
                resourceType: 'platform_maintenance',
                reasonCodes: ['capability_missing'],
                policyVersion: 'test-v1',
                factsVersion: 'test-v1',
                classification: 'internal',
            ));
        $this->app->instance(DecideAccess::class, $access);
        $this->app->instance(ResolvePrincipalContext::class, $this->principalsReturning($this->buildPrincipal()));

        $request = $this->buildProtectedRequest();
        $request->headers->set('X-Correlation-ID', $customCorrelation);
        $response = $this->app->make(EnforcePlatformMaintenance::class)
            ->handle($request, fn (Request $passed): mixed => response('next-called', 200));

        $this->assertSame(503, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $shape
     */
    private function seedCache(array $shape): void
    {
        Cache::put(self::CACHE_KEY, $shape, 60);
    }

    /**
     * @return array<string, mixed>
     */
    private function activeWindowShape(): array
    {
        return [
            'active' => true,
            'id' => '018f6f7d-0c00-7000-8000-000000000601',
            'starts_at' => (new DateTimeImmutable())->modify('-5 minutes')->format(DateTimeImmutable::ATOM),
            'ends_at' => (new DateTimeImmutable())->modify('+2 hours')->format(DateTimeImmutable::ATOM),
            'message_ar' => 'صيانة مجدولة',
            'message_en' => 'planned',
            'status' => 'active',
        ];
    }

    private function buildPrincipal(): PrincipalContext
    {
        return new PrincipalContext(
            userId: self::USER_ID,
            personId: null,
            accountStatus: 'active',
            clusterIds: [],
            facilityIds: [self::FACILITY_ID],
            organizationUnitIds: [self::ORG_UNIT_ID],
            primaryOrganizationUnitId: self::ORG_UNIT_ID,
            selectedScope: ['scope_type' => 'facility', 'scope_id' => self::FACILITY_ID],
            sessionRestricted: false,
        );
    }

    private function buildProtectedRequest(): Request
    {
        // POST so the GET/HEAD/OPTIONS bypass in isAllowedRequest does
        // not short-circuit the maintenance check. /api/v1/some-protected-resource
        // is not in the exempt path list.
        $request = Request::create('/api/v1/some-protected-resource', 'POST');
        $request->headers->set('X-Correlation-ID', self::CORRELATION_ID);

        return $request;
    }

    private function principalsReturning(?PrincipalContext $principal): ResolvePrincipalContext
    {
        $mock = Mockery::mock(ResolvePrincipalContext::class);
        $mock->shouldReceive('resolve')->andReturn($principal);

        return $mock;
    }

    private function accessNeverCalled(): DecideAccess
    {
        $mock = Mockery::mock(DecideAccess::class);
        $mock->shouldNotReceive('decide');

        return $mock;
    }
}
