<?php

namespace Modules\Authorization\Tests;

use App\Http\Authentication\SessionPrincipalResolver;
use App\Http\Controllers\Authorization\AuthorizationAdminController;
use App\Http\Controllers\Authorization\DecideAccessController;
use App\Http\Controllers\Authorization\ExplainAccessDecisionController;
use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\AuthorizationResourceReference;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Contracts\ResolveAuthorizationSimulationFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Tests\TestCase;

final class AuthorizationHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000901';

    private const SESSION_COOKIE = 'cluster_identity_session';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->when([
            AuthorizationAdminController::class,
            DecideAccessController::class,
            ExplainAccessDecisionController::class,
        ])->needs(ResolveDevelopmentFixturePrincipal::class)
            ->give(fn ($app) => $app->make(SessionPrincipalResolver::class));
        $this->seed(DevelopmentJourneyAuthorizationSeeder::class);
        config()->set('identity.session_only', true);
        $this->bindRealAccessDecision();
        DB::table('authorization_bootstrap')->update([
            'state' => 'complete',
            'completed_by_user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID,
            'completed_at' => now(),
            'lock_version' => 2,
            'updated_at' => now(),
        ]);
        $this->app->forgetInstance(\Modules\Authorization\Contracts\DecideAccess::class);
        $this->app->bind(ResolveAuthorizationSimulationFacts::class, function (): ResolveAuthorizationSimulationFacts {
            return new class implements ResolveAuthorizationSimulationFacts
            {
                public function resolve(AuthorizationResourceReference $reference): ?RecordFacts
                {
                    if ($reference->type !== 'work_record') {
                        return null;
                    }

                    return new RecordFacts(
                        ownerFacilityId: DevelopmentJourneyAuthorizationSeeder::FACILITY_A_ID,
                        resourceType: $reference->type,
                        classification: 'internal',
                        factsVersion: 'trusted-http-test-v1',
                        recordId: $reference->id,
                    );
                }
            };
        });
    }

    public function test_authorization_admin_role_is_created_replayed_listed_and_versioned(): void
    {
        [$cookie, $csrf] = $this->loginSession(
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_USERNAME,
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_PASSWORD,
        );
        $body = [
            'resource_type' => 'role',
            'code' => 'quality_manager',
            'name' => 'مدير الجودة',
            'role_type' => 'administrative',
        ];
        $headers = [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'Idempotency-Key' => 'role-create',
            'X-CSRF-Token' => $csrf,
        ];
        $created = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/roles', $body, $headers)
            ->assertCreated()->assertHeader('ETag', '"1"')->assertJsonPath('data.resource_type', 'role');
        $id = (string) $created->json('data.id');
        DB::table('role_assignments')->insert([
            'id' => \Illuminate\Support\Str::uuid7()->toString(),
            'user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID,
            'role_id' => $id,
            'scope_type' => 'facility',
            'scope_id' => DevelopmentJourneyAuthorizationSeeder::FACILITY_A_ID,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/roles', $body, $headers)
            ->assertCreated()->assertJsonPath('data.code', $body['code']);
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/roles', [...$body, 'name' => 'مختلف'], $headers)
            ->assertConflict()->assertJsonPath('type', 'https://cluster.example/problems/idempotency-conflict');
        $list = $this->withIdentitySession($cookie)->getJson('/api/v1/authorization/roles?limit=100', ['X-Correlation-ID' => self::CORRELATION_ID])->assertOk();
        $this->assertContains($id, array_column($list->json('items'), 'id'));
        $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/roles/'.$id, ['name' => 'مدير الجودة الأول'], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Content-Type' => 'application/merge-patch+json',
            'X-CSRF-Token' => $csrf,
        ])->assertOk()->assertHeader('ETag', '"2"');
    }

    public function test_authorization_http_fails_closed_for_anonymous_and_other_user(): void
    {
        $this->getJson('/api/v1/authorization/roles', ['X-Correlation-ID' => self::CORRELATION_ID])->assertUnauthorized();
        [$otherCookie] = $this->loginSession(
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_B_USERNAME,
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_B_PASSWORD,
        );
        $this->withIdentitySession($otherCookie)->getJson('/api/v1/authorization/roles', ['X-Correlation-ID' => self::CORRELATION_ID])
            ->assertForbidden();
    }

    public function test_identity_account_manager_cannot_use_authorization_admin_api(): void
    {
        [$otherCookie] = $this->loginSession(
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_B_USERNAME,
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_B_PASSWORD,
        );

        $this->withIdentitySession($otherCookie)->getJson('/api/v1/authorization/roles', [
            'X-Correlation-ID' => self::CORRELATION_ID,
        ])->assertForbidden();
    }

    public function test_access_decision_is_recorded_and_explanation_is_safe(): void
    {
        [$cookie, $csrf] = $this->loginSession(
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_USERNAME,
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_PASSWORD,
        );
        $recordId = '018f6f7d-0c00-7000-8000-000000000902';
        $payload = [
            'action' => 'work_record.read',
            'resource_reference' => [
                'type' => 'work_record',
                'id' => $recordId,
            ],
            'access_context' => [
                'subject_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_B_ID,
                'tenant_id' => DevelopmentJourneyAuthorizationSeeder::FACILITY_B_ID,
                'roles' => ['super-admin'],
                'clearance' => 'top_secret',
                'correlation_id' => self::CORRELATION_ID,
            ],
            'record_facts' => [
                'facts_version' => 'browser-forged-v1',
                'record_type' => 'work_record',
                'record_id' => $recordId,
                'owner_facility_id' => DevelopmentJourneyAuthorizationSeeder::FACILITY_B_ID,
                'classification' => 'top_secret',
            ],
        ];
        $decision = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/access-decisions', $payload, [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'X-CSRF-Token' => $csrf,
        ])->assertOk()->assertJsonPath('action', 'work_record.read');
        $id = (string) $decision->json('decision_id');
        $this->assertDatabaseHas('access_decisions', [
            'id' => $id,
            'facts_version' => 'trusted-http-test-v1',
            'classification' => 'internal',
            'actor_user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID,
        ]);
        $this->assertSame(1, DB::table('access_decisions')->where('id', $id)->count());
        $this->withIdentitySession($cookie)->getJson('/api/v1/authorization/access-decisions/'.$id.'/explanation', [
            'X-Correlation-ID' => self::CORRELATION_ID,
        ])->assertOk()->assertJsonPath('decision_id', $id)->assertJsonMissingPath('source_ip');
    }

    public function test_unknown_simulation_resource_type_fails_closed_without_persistence(): void
    {
        [$cookie, $csrf] = $this->loginSession(
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_USERNAME,
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_PASSWORD,
        );

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/access-decisions', [
            'action' => 'work_record.read',
            'resource_reference' => [
                'type' => 'unknown_resource',
                'id' => '018f6f7d-0c00-7000-8000-000000000903',
            ],
        ], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'X-CSRF-Token' => $csrf,
        ])->assertForbidden()->assertJsonPath('type', 'https://cluster.example/problems/access-denied');

        $this->assertDatabaseCount('access_decisions', 0);
    }

    public function test_explanation_conceals_a_decision_from_another_persisted_scope(): void
    {
        [$cookie, $csrf] = $this->loginSession(
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_USERNAME,
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_PASSWORD,
        );
        $decision = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/access-decisions', [
            'action' => 'work_record.read',
            'resource_reference' => [
                'type' => 'work_record',
                'id' => '018f6f7d-0c00-7000-8000-000000000904',
            ],
        ], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'X-CSRF-Token' => $csrf,
        ])->assertOk();
        $id = (string) $decision->json('decision_id');

        DB::table('access_decisions')->where('id', $id)->update([
            'access_context' => json_encode([
                'user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_B_ID,
                'facility_id' => DevelopmentJourneyAuthorizationSeeder::FACILITY_B_ID,
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->withIdentitySession($cookie)->getJson('/api/v1/authorization/access-decisions/'.$id.'/explanation', [
            'X-Correlation-ID' => self::CORRELATION_ID,
        ])->assertNotFound()->assertJsonPath('type', 'https://cluster.example/problems/decision-not-found');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function loginSession(string $username, string $password): array
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'W1.2 E2E test browser']);
        $response = $this->postJson('/api/v1/identity/login', [
            'username' => $username,
            'password' => $password,
        ], ['X-Correlation-ID' => self::CORRELATION_ID]);
        $response->assertOk();
        $this->assertCount(1, $response->headers->getCookies());
        $this->assertSame(self::SESSION_COOKIE, $response->headers->getCookies()[0]->getName());

        return [
            (string) $response->headers->getCookies()[0]->getValue(),
            (string) $response->json('data.csrf_token'),
        ];
    }

    private function withIdentitySession(string $cookie): self
    {
        return $this->withUnencryptedCookie(self::SESSION_COOKIE, $cookie)->withCredentials();
    }
}
