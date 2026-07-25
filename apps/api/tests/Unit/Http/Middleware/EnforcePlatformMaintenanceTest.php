<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\EnforcePlatformMaintenance;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\PrincipalContext;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Modules\PlatformSettings\Domain\MaintenanceWindow;
use Modules\PlatformSettings\Features\Maintenance\Handler\MaintenanceWindowHandler;
use Tests\TestCase;

final class EnforcePlatformMaintenanceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000011';

    private const ORG_UNIT_ID = '018f6f7d-0c00-7000-8000-000000000012';

    private const USER_ID = '018f6f7d-0c00-7000-8000-000000000021';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000501';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('platform:maintenance:active');
    }


    public function test_inactive_maintenance_window_passes_through_and_never_consults_principals_or_decide_access(): void
    {
        $principals = Mockery::mock(ResolvePrincipalContext::class);
        $principals->shouldNotReceive('resolve');

        $access = $this->mockAccess();
        $access->shouldNotReceive('decide');

        $this->bindMiddleware(
            $this->mockMaintenanceWindow(null),
            $principals,
            $access,
        );

        $middleware = $this->app->make(EnforcePlatformMaintenance::class);

        $request = $this->buildProtectedRequest();
        $response = $middleware->handle($request, fn (Request $passed): mixed => response('next-called', 200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('next-called', $response->getContent());
    }

    public function test_active_maintenance_window_blocks_non_admin_principal_with_503(): void
    {
        $access = $this->mockAccess();
        $access->shouldReceive('decide')
            ->once()
            ->withArgs(function (array $actor, string $capability, $facts): bool {
                $this->assertSame(self::USER_ID, $actor['user_id'] ?? null);
                $this->assertSame(self::FACILITY_ID, $actor['facility_id'] ?? null);
                $this->assertSame(self::CORRELATION_ID, $actor['correlation_id'] ?? null);
                $this->assertSame('platform_operations.maintenance.manage', $capability);
                $this->assertInstanceOf(RecordFacts::class, $facts);
                $this->assertSame('platform_maintenance', $facts->resourceType);
                $this->assertSame('internal', $facts->classification);

                return true;
            })
            ->andReturn(new AccessDecision(
                decision: 'deny',
                action: 'manage',
                resourceType: 'platform_maintenance',
                reasonCodes: ['capability_missing'],
                policyVersion: 'test-v1',
                factsVersion: 'test-v1',
                classification: 'internal',
            ));

        $this->bindMiddleware(
            $this->mockMaintenanceWindow($this->activeWindow()),
            $this->mockPrincipals($this->standardPrincipal()),
            $access,
        );

        $middleware = $this->app->make(EnforcePlatformMaintenance::class);

        $request = $this->buildProtectedRequest();
        $response = $middleware->handle($request, fn (Request $passed): mixed => response('next-called', 200));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $this->assertNotNull($response->headers->get('Retry-After'));

        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('https://cluster.example/problems/platform-maintenance', $body['type'] ?? null);
        $this->assertSame('platform-maintenance', $body['type_key'] ?? null);
        $this->assertSame(503, $body['status'] ?? null);
        $this->assertSame('Scheduled maintenance window', $body['detail'] ?? null);
    }

    public function test_active_maintenance_window_admits_admin_principal_and_invokes_decide_access_once(): void
    {
        $access = $this->mockAccess();
        $access->shouldReceive('decide')
            ->once()
            ->withArgs(function (array $actor, string $capability, $facts): bool {
                $this->assertSame(self::USER_ID, $actor['user_id'] ?? null);
                $this->assertSame(self::FACILITY_ID, $actor['facility_id'] ?? null);
                $this->assertSame(self::CORRELATION_ID, $actor['correlation_id'] ?? null);
                $this->assertSame('platform_operations.maintenance.manage', $capability);
                $this->assertInstanceOf(RecordFacts::class, $facts);
                $this->assertSame('platform_maintenance', $facts->resourceType);
                $this->assertSame('internal', $facts->classification);

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

        $this->bindMiddleware(
            $this->mockMaintenanceWindow($this->activeWindow()),
            $this->mockPrincipals($this->standardPrincipal()),
            $access,
        );

        $middleware = $this->app->make(EnforcePlatformMaintenance::class);

        $request = $this->buildProtectedRequest();
        $response = $middleware->handle($request, fn (Request $passed): mixed => response('next-called', 200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('next-called', $response->getContent());
    }

    private function mockAccess(): MockInterface
    {
        return Mockery::mock(DecideAccess::class);
    }

    private function mockMaintenanceWindow(?MaintenanceWindow $window): MaintenanceWindowHandler
    {
        $handler = Mockery::mock(MaintenanceWindowHandler::class);
        $handler->shouldReceive('activeAt')->andReturn($window);

        return $handler;
    }

    private function mockPrincipals(?PrincipalContext $principal): ResolvePrincipalContext
    {
        $resolver = Mockery::mock(ResolvePrincipalContext::class);
        $resolver->shouldReceive('resolve')->andReturn($principal);

        return $resolver;
    }

    private function bindMiddleware(
        MaintenanceWindowHandler $handler,
        ResolvePrincipalContext $principals,
        DecideAccess $access,
    ): void {
        $this->app->instance(MaintenanceWindowHandler::class, $handler);
        $this->app->instance(ResolvePrincipalContext::class, $principals);
        $this->app->instance(DecideAccess::class, $access);
        $this->app->forgetInstance(EnforcePlatformMaintenance::class);
    }

    private function buildProtectedRequest(): Request
    {
        $request = Request::create('/api/v1/documents/uploads', 'POST');
        $request->headers->set('X-Correlation-ID', self::CORRELATION_ID);
        $request->headers->set('Accept-Language', 'en');

        return $request;
    }

    private function activeWindow(): MaintenanceWindow
    {
        return new MaintenanceWindow(
            id: '018f6f7d-0c00-7000-8000-000000000701',
            startsAt: new DateTimeImmutable('-5 minutes'),
            endsAt: new DateTimeImmutable('+30 minutes'),
            messageAr: 'صيانة مجدولة',
            messageEn: 'Scheduled maintenance window',
            status: 'active',
        );
    }

    private function standardPrincipal(): PrincipalContext
    {
        return new PrincipalContext(
            userId: self::USER_ID,
            personId: null,
            accountStatus: 'active',
            clusterIds: [],
            facilityIds: [self::FACILITY_ID],
            organizationUnitIds: [self::ORG_UNIT_ID],
            primaryOrganizationUnitId: self::ORG_UNIT_ID,
            selectedScope: null,
            sessionRestricted: false,
        );
    }
}
