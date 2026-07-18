<?php

namespace Modules\Authorization\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuthorizationHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000901';

    public function test_authorization_admin_role_is_created_replayed_listed_and_versioned(): void
    {
        $token = $this->loginToken();
        $body = [
            'resource_type' => 'role',
            'code' => 'quality_manager',
            'name' => 'مدير الجودة',
            'role_type' => 'administrative',
        ];
        $headers = ['X-Correlation-ID' => self::CORRELATION_ID, 'Idempotency-Key' => 'role-create'];
        $created = $this->withToken($token)->postJson('/api/v1/authorization/roles', $body, $headers)
            ->assertCreated()->assertHeader('ETag', '"1"')->assertJsonPath('data.resource_type', 'role');
        $id = (string) $created->json('data.id');
        $this->withToken($token)->postJson('/api/v1/authorization/roles', $body, $headers)
            ->assertCreated()->assertJsonPath('data.id', $id);
        $this->withToken($token)->postJson('/api/v1/authorization/roles', [...$body, 'name' => 'مختلف'], $headers)
            ->assertConflict()->assertJsonPath('type', 'https://cluster.example/problems/idempotency-conflict');
        $this->withToken($token)->getJson('/api/v1/authorization/roles?limit=1', ['X-Correlation-ID' => self::CORRELATION_ID])
            ->assertOk()->assertJsonPath('items.0.id', $id);
        $this->withToken($token)->patchJson('/api/v1/authorization/roles/'.$id, ['name' => 'مدير الجودة الأول'], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Content-Type' => 'application/merge-patch+json',
        ])->assertOk()->assertHeader('ETag', '"2"');
    }

    public function test_authorization_http_fails_closed_for_anonymous_and_other_fixture(): void
    {
        $this->getJson('/api/v1/authorization/roles', ['X-Correlation-ID' => self::CORRELATION_ID])->assertUnauthorized();
        $other = $this->loginToken('fixture-account-b', 'fixture-password-b');
        $this->withToken($other)->getJson('/api/v1/authorization/roles', ['X-Correlation-ID' => self::CORRELATION_ID])->assertForbidden();
    }

    public function test_access_decision_is_recorded_and_explanation_is_safe(): void
    {
        $token = $this->loginToken();
        $recordId = '018f6f7d-0c00-7000-8000-000000000902';
        $payload = [
            'action' => 'work_record.read',
            'access_context' => [
                'subject_id' => '018f6f7d-0c00-7000-8000-000000000021',
                'tenant_id' => '018f6f7d-0c00-7000-8000-000000000011',
                'clearance' => 'internal',
                'correlation_id' => self::CORRELATION_ID,
            ],
            'record_facts' => [
                'facts_version' => 'facts-v1',
                'record_type' => 'work_record',
                'record_id' => $recordId,
                'owner_facility_id' => '018f6f7d-0c00-7000-8000-000000000011',
                'owner_organization_unit_id' => null,
                'classification' => 'internal',
            ],
        ];
        $decision = $this->withToken($token)->postJson('/api/v1/authorization/access-decisions', $payload, [
            'X-Correlation-ID' => self::CORRELATION_ID,
        ])->assertOk()->assertJsonPath('action', 'work_record.read');
        $id = (string) $decision->json('decision_id');
        $this->assertDatabaseHas('access_decisions', ['id' => $id]);
        $this->withToken($token)->getJson('/api/v1/authorization/access-decisions/'.$id.'/explanation', [
            'X-Correlation-ID' => self::CORRELATION_ID,
        ])->assertOk()->assertJsonPath('decision_id', $id)->assertJsonMissingPath('source_ip');
    }

    private function loginToken(string $username = 'fixture-account-a', string $password = 'fixture-password-a'): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'username' => $username,
            'password' => $password,
        ], ['X-Correlation-ID' => self::CORRELATION_ID])->assertOk()->json('data.access_token');
    }
}
