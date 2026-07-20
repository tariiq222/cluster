<?php

namespace Modules\Authorization\Tests;

use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            \App\Http\Controllers\Authorization\AuthorizationAdminController::class,
            \App\Http\Controllers\Authorization\DecideAccessController::class,
            \App\Http\Controllers\Authorization\ExplainAccessDecisionController::class,
        ])->needs(\Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal::class)
            ->give(fn ($app) => $app->make(\App\Http\Authentication\SessionPrincipalResolver::class));
        $this->seed(DevelopmentJourneyAuthorizationSeeder::class);
        $this->bindRealAccessDecision();
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
            'access_context' => [
                'subject_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID,
                'tenant_id' => DevelopmentJourneyAuthorizationSeeder::FACILITY_A_ID,
                'clearance' => 'internal',
                'correlation_id' => self::CORRELATION_ID,
            ],
            'record_facts' => [
                'facts_version' => 'facts-v1',
                'record_type' => 'work_record',
                'record_id' => $recordId,
                'owner_facility_id' => DevelopmentJourneyAuthorizationSeeder::FACILITY_A_ID,
                'owner_organization_unit_id' => null,
                'classification' => 'internal',
            ],
        ];
        $decision = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/access-decisions', $payload, [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'X-CSRF-Token' => $csrf,
        ])->assertOk()->assertJsonPath('action', 'work_record.read');
        $id = (string) $decision->json('decision_id');
        $this->assertDatabaseHas('access_decisions', ['id' => $id]);
        $this->withIdentitySession($cookie)->getJson('/api/v1/authorization/access-decisions/'.$id.'/explanation', [
            'X-Correlation-ID' => self::CORRELATION_ID,
        ])->assertOk()->assertJsonPath('decision_id', $id)->assertJsonMissingPath('source_ip');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function loginSession(string $username, string $password): array
    {
        $this->withServerVariables(["REMOTE_ADDR" => "127.0.0.1", "HTTP_USER_AGENT" => "W1.2 E2E test browser"]);
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