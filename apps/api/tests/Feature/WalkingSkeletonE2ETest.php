<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalkingSkeletonE2ETest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT_A_CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000001';

    private const ACCOUNT_B_CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000002';

    public function test_account_a_can_submit_and_read_only_its_request_then_receive_a_notification(): void
    {
        $session = $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'fixture-password-a',
        ], $this->correlationHeaders(self::ACCOUNT_A_CORRELATION_ID));

        $session->assertOk()
            ->assertJsonPath('data.facility', 'facility-a');

        $token = $session->json('data.access_token');

        $created = $this->withToken($token)->postJson('/api/v1/work-records', [
            'work_definition_code' => 'request',
            'title' => 'طلب حساب أ',
            'description' => 'وصف لا يراه إلا حساب المنشأة أ.',
        ], $this->writeHeaders(self::ACCOUNT_A_CORRELATION_ID, 'a-request-001'));

        $created->assertCreated()
            ->assertJsonPath('data.payload.title', 'طلب حساب أ');

        $recordId = $created->json('data.id');

        $this->withToken($token)->getJson('/api/v1/work-records', $this->correlationHeaders(self::ACCOUNT_A_CORRELATION_ID))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.payload.title', 'طلب حساب أ');

        $this->withToken($token)->getJson("/api/v1/work-records/{$recordId}", $this->correlationHeaders(self::ACCOUNT_A_CORRELATION_ID))
            ->assertOk()
            ->assertJsonPath('data.payload.description', 'وصف لا يراه إلا حساب المنشأة أ.');

        $this->withToken($token)->getJson('/api/v1/notifications', $this->correlationHeaders(self::ACCOUNT_A_CORRELATION_ID))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.record_id', $recordId);
    }

    public function test_account_b_gets_the_unified_unavailable_response_without_account_a_metadata(): void
    {
        $session = $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-b',
            'password' => 'fixture-password-b',
        ], $this->correlationHeaders(self::ACCOUNT_B_CORRELATION_ID));

        $session->assertOk();

        $response = $this->withToken($session->json('data.access_token'))
            ->getJson('/api/v1/work-records/018f6f7d-0c00-7000-8000-000000000010', $this->correlationHeaders(self::ACCOUNT_B_CORRELATION_ID));

        $response->assertNotFound()
            ->assertJsonPath('detail', 'لا يمكنك فتح هذا الطلب أو لم يعد متاحاً.')
            ->assertJsonMissingPath('title')
            ->assertJsonMissingPath('description')
            ->assertJsonMissingPath('facility')
            ->assertJsonMissingPath('authorization_trace_id');
    }

    /**
     * @return array<string, string>
     */
    private function correlationHeaders(string $correlationId): array
    {
        return ['X-Correlation-ID' => $correlationId];
    }

    /**
     * @return array<string, string>
     */
    private function writeHeaders(string $correlationId, string $idempotencyKey): array
    {
        return [
            ...$this->correlationHeaders($correlationId),
            'Idempotency-Key' => $idempotencyKey,
        ];
    }
}
