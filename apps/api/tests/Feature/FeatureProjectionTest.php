<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Authorization\Contracts\CapabilityCatalog;
use Modules\Identity\Contracts\AuthorizeIdentityManagement;
use Modules\Identity\Contracts\PrincipalContext;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Tests\TestCase;

/**
 * Feature projection of the principal — see
 * docs/superpowers/plans/2026-07-28-cluster-task-only-workspace.md (Task 2).
 *
 * The fixture bearer flow mints a real token via /auth/login but the existing
 * PrincipalContext resolution stack is not exercised end-to-end here; we bind
 * a deterministic fake ResolvePrincipalContext + AuthorizeIdentityManagement so
 * the projection shape is the only contract under test.
 */
final class FeatureProjectionTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000201';

    private const USER_ID = '018f6f7d-0c00-7000-8000-000000000021';

    private const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000011';

    private const CLUSTER_ID = '018f6f7d-0c00-7000-8000-000000000009';

    protected function setUp(): void
    {
        parent::setUp();

        $userId = self::USER_ID;
        $facilityId = self::FACILITY_ID;
        $clusterId = self::CLUSTER_ID;

        $this->app->instance(ResolvePrincipalContext::class, new class($userId, $facilityId, $clusterId) implements ResolvePrincipalContext
        {
            public function __construct(
                private readonly string $userId,
                private readonly string $facilityId,
                private readonly string $clusterId,
            ) {}

            public function resolve(Request $request): PrincipalContext
            {
                return new PrincipalContext(
                    userId: $this->userId,
                    personId: null,
                    accountStatus: 'active',
                    clusterIds: [$this->clusterId],
                    facilityIds: [$this->facilityId],
                    organizationUnitIds: [],
                    primaryOrganizationUnitId: null,
                    selectedScope: null,
                    sessionRestricted: false,
                );
            }

            public function resolveSelectedScope(Request $request): ?array
            {
                return null;
            }

            public function persistSelectedScope(Request $request, string $scopeType, string $scopeId): void
            {
                // no-op — projection is read-side only.
            }
        });

        $this->app->instance(AuthorizeIdentityManagement::class, new class implements AuthorizeIdentityManagement
        {
            public function canReadAccounts(array $principal): bool
            {
                return true;
            }

            public function canManageAccounts(array $principal): bool
            {
                return false;
            }

            public function canIssueActivation(array $principal): bool
            {
                return false;
            }

            public function principalAccess(string $userId): array
            {
                return [
                    'roles' => [],
                    'capabilities' => [],
                    'clearance' => 'public',
                ];
            }
        });
    }

    private function bearerToken(): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'fixture-password-a',
        ], ['X-Correlation-ID' => self::CORRELATION_ID])->assertOk()->json('data.access_token');
    }

    public function test_get_me_projects_features_block_with_work_management_disabled_and_tasks_enabled_by_default(): void
    {
        config()->set('features.work_management', false);
        config()->set('features.tasks', true);

        $token = $this->bearerToken();
        $response = $this->withToken($token)
            ->getJson('/api/v1/me', ['X-Correlation-ID' => self::CORRELATION_ID]);

        $response->assertOk();
        $this->assertFalse($response->json('features.work_management'));
        $this->assertTrue($response->json('features.tasks'));
    }

    public function test_get_me_projects_work_management_true_when_config_flag_is_enabled(): void
    {
        config()->set('features.work_management', true);

        $token = $this->bearerToken();
        $response = $this->withToken($token)
            ->getJson('/api/v1/me', ['X-Correlation-ID' => self::CORRELATION_ID]);

        $response->assertOk();
        $this->assertTrue($response->json('features.work_management'));
    }

    public function test_capability_catalog_supports_work_management_history_read(): void
    {
        $this->assertTrue(CapabilityCatalog::supports('work_management.history.read'));
        $this->assertSame('normal', CapabilityCatalog::sensitivity('work_management.history.read'));
    }
}
