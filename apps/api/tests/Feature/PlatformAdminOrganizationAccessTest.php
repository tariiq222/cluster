<?php

namespace Tests\Feature;

use App\Http\Authentication\SessionPrincipalResolver;
use Database\Seeders\AuthorizationCatalogSeeder;
use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Infrastructure\RbacAbacDecideAccess;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Tests\TestCase;

/**
 * Confirms the platform-admin user can actually reach the organization
 * management endpoints with a 200, not a 403. Reproduces the runtime
 * symptom reported by the platform admin: "some pages say I don't have
 * permission to manage employees and assignments".
 */
final class PlatformAdminOrganizationAccessTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-00000000a001';

    private const SESSION_COOKIE = 'cluster_identity_session';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindRealAccessDecision();
        $this->app->bind(ResolveDevelopmentFixturePrincipal::class, SessionPrincipalResolver::class);
        $this->seed(AuthorizationCatalogSeeder::class);
        $this->seed(DevelopmentJourneyAuthorizationSeeder::class);
        config()->set('identity.session_only', true);
        DB::table('authorization_bootstrap')->update([
            'state' => 'complete',
            'completed_by_user_id' => DevelopmentJourneyAuthorizationSeeder::PLATFORM_ADMIN_ACCOUNT_ID,
            'completed_at' => now(),
            'lock_version' => 2,
            'updated_at' => now(),
        ]);
    }

    public function test_platform_admin_can_list_people_positions_and_assignments(): void
    {
        $cookie = $this->loginSession(
            DevelopmentJourneyAuthorizationSeeder::PLATFORM_ADMIN_USERNAME,
            DevelopmentJourneyAuthorizationSeeder::PLATFORM_ADMIN_PASSWORD,
        );

        $headers = ['X-Correlation-ID' => self::CORRELATION_ID];

        $cluster = $this->withUnencryptedCookie(self::SESSION_COOKIE, $cookie)
            ->withCredentials()
            ->getJson('/api/v1/organization/cluster', $headers);
        $cluster->assertOk();

        $people = $this->withUnencryptedCookie(self::SESSION_COOKIE, $cookie)
            ->withCredentials()
            ->getJson('/api/v1/organization/people', $headers);
        $people->assertOk();

        $positions = $this->withUnencryptedCookie(self::SESSION_COOKIE, $cookie)
            ->withCredentials()
            ->getJson('/api/v1/organization/positions', $headers);
        $positions->assertOk();

        $assignments = $this->withUnencryptedCookie(self::SESSION_COOKIE, $cookie)
            ->withCredentials()
            ->getJson('/api/v1/organization/assignments', $headers);
        $assignments->assertOk();

        $facilities = $this->withUnencryptedCookie(self::SESSION_COOKIE, $cookie)
            ->withCredentials()
            ->getJson('/api/v1/organization/facilities', $headers);
        $facilities->assertOk();

        $units = $this->withUnencryptedCookie(self::SESSION_COOKIE, $cookie)
            ->withCredentials()
            ->getJson('/api/v1/organization/units', $headers);
        $units->assertOk();

        $unitId = $units->json('items.0.id');
        $this->assertIsString($unitId);
        $temporaries = $this->withUnencryptedCookie(self::SESSION_COOKIE, $cookie)
            ->withCredentials()
            ->getJson('/api/v1/organization/temporary-assignments?'.http_build_query([
                'organization_unit_id' => $unitId,
            ]), $headers);
        $temporaries->assertOk();
    }

    private function loginSession(string $username, string $password): string
    {
        $response = $this->postJson('/api/v1/identity/login', [
            'username' => $username,
            'password' => $password,
        ], ['X-Correlation-ID' => self::CORRELATION_ID]);
        $response->assertOk();
        $this->assertCount(1, $response->headers->getCookies());

        return (string) $response->headers->getCookies()[0]->getValue();
    }

    protected function bindRealAccessDecision(): void
    {
        $this->app->forgetInstance(DecideAccess::class);
        $this->app->singleton(
            DecideAccess::class,
            RbacAbacDecideAccess::class,
        );
    }
}
