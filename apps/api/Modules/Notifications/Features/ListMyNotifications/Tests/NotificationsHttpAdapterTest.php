<?php

namespace Modules\Notifications\Features\ListMyNotifications\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Notifications\Features\ListMyNotifications\Http\ListMyNotificationsController;
use Tests\TestCase;

class NotificationsHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000001';

    private const USER_A_ID = '018f6f7d-0c00-7000-8000-000000000021';

    private const USER_B_ID = '018f6f7d-0c00-7000-8000-000000000022';

    public function test_notifications_http_adapter_is_present(): void
    {
        if (! class_exists(ListMyNotificationsController::class)) {
            $this->fail('MISSING_NOTIFICATIONS_HTTP_ADAPTER');
        }

        $this->assertTrue(class_exists(ListMyNotificationsController::class));
    }

    public function test_missing_and_unknown_bearer_credentials_return_the_named_problem_with_correlation(): void
    {
        $this->requireAdapter();

        foreach ([null, str_repeat('A', 64)] as $token) {
            $response = $token === null
                ? $this->getJson('/api/v1/notifications', $this->correlationHeaders())
                : $this->withToken($token)->getJson('/api/v1/notifications', $this->correlationHeaders());

            $response->assertUnauthorized()
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertHeader('X-Correlation-ID', self::CORRELATION_ID)
                ->assertExactJson([
                    'type' => 'https://cluster.example/problems/authentication-required',
                    'title' => 'Unauthorized',
                    'status' => 401,
                    'detail' => 'Authentication is required.',
                ]);
        }
    }

    public function test_invalid_correlation_is_rejected_before_authentication(): void
    {
        $this->requireAdapter();

        $this->getJson('/api/v1/notifications', ['X-Correlation-ID' => 'not-a-uuid'])
            ->assertBadRequest()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeaderMissing('X-Correlation-ID')
            ->assertExactJson([
                'type' => 'https://cluster.example/problems/invalid-correlation-id',
                'title' => 'Bad Request',
                'status' => 400,
                'detail' => 'X-Correlation-ID must be a lowercase UUIDv7.',
            ]);
    }

    public function test_collection_is_recipient_scoped_and_serializes_only_the_closed_typed_contract(): void
    {
        $this->requireAdapter();
        $tokenA = $this->login('fixture-account-a', 'fixture-password-a')->json('data.access_token');
        $tokenB = $this->login('fixture-account-b', 'fixture-password-b')->json('data.access_token');
        $this->insertNotification(
            '018f6f7d-0c00-7000-8000-000000000601',
            '018f6f7d-0c00-7000-8000-000000000701',
            self::USER_A_ID,
            '018f6f7d-0c00-7000-8000-000000000401',
            '2026-07-16 09:00:00',
        );
        $this->insertNotification(
            '018f6f7d-0c00-7000-8000-000000000602',
            '018f6f7d-0c00-7000-8000-000000000702',
            self::USER_B_ID,
            '018f6f7d-0c00-7000-8000-000000000402',
            '2026-07-16 09:01:00',
        );

        $responseA = $this->withToken($tokenA)->getJson('/api/v1/notifications', $this->correlationHeaders());
        $responseB = $this->withToken($tokenB)->getJson('/api/v1/notifications', $this->correlationHeaders());

        $responseA->assertOk()->assertHeader('X-Correlation-ID', self::CORRELATION_ID)->assertExactJson([
            'items' => [[
                'id' => '018f6f7d-0c00-7000-8000-000000000601',
                'title' => 'تم تقديم سجل عمل',
                'source' => [
                    'source_module' => 'work_records',
                    'record_type' => 'work_record',
                    'record_id' => '018f6f7d-0c00-7000-8000-000000000401',
                ],
                'is_read' => false,
                'created_at' => '2026-07-16T09:00:00.000Z',
            ]],
            'next_cursor' => null,
        ]);
        $responseB->assertOk()->assertJsonPath('items.0.source.record_id', '018f6f7d-0c00-7000-8000-000000000402');

        foreach (['payload', 'description', 'facility', 'recipient', 'reason', 'trace', 'authorization', 'credential'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $responseA->getContent());
            $this->assertStringNotContainsString($forbidden, $responseB->getContent());
        }
    }

    public function test_cursor_pagination_is_stable_and_bound_to_the_authenticated_principal(): void
    {
        $this->requireAdapter();
        $tokenA = $this->login('fixture-account-a', 'fixture-password-a')->json('data.access_token');
        $tokenB = $this->login('fixture-account-b', 'fixture-password-b')->json('data.access_token');

        foreach ([1, 2, 3] as $sequence) {
            $this->insertNotification(
                sprintf('018f6f7d-0c00-7000-8000-00000000060%d', $sequence),
                sprintf('018f6f7d-0c00-7000-8000-00000000070%d', $sequence),
                self::USER_A_ID,
                sprintf('018f6f7d-0c00-7000-8000-00000000040%d', $sequence),
                sprintf('2026-07-16 09:0%d:00', $sequence),
            );
        }

        $firstPage = $this->withToken($tokenA)->getJson('/api/v1/notifications?limit=2', $this->correlationHeaders())
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.id', '018f6f7d-0c00-7000-8000-000000000603')
            ->assertJsonPath('items.1.id', '018f6f7d-0c00-7000-8000-000000000602');
        $cursor = $firstPage->json('next_cursor');
        $this->assertIsString($cursor);
        $this->assertNotSame('', $cursor);

        $this->withToken($tokenA)->getJson('/api/v1/notifications?limit=2&cursor='.urlencode($cursor), $this->correlationHeaders())
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', '018f6f7d-0c00-7000-8000-000000000601')
            ->assertJsonPath('next_cursor', null);
        $this->withToken($tokenB)->getJson('/api/v1/notifications?limit=2&cursor='.urlencode($cursor), $this->correlationHeaders())
            ->assertBadRequest()
            ->assertExactJson([
                'type' => 'https://cluster.example/problems/invalid-notifications-query',
                'title' => 'Bad Request',
                'status' => 400,
                'detail' => 'The notifications query is invalid.',
            ]);
    }

    private function requireAdapter(): void
    {
        if (! class_exists(ListMyNotificationsController::class)) {
            $this->markTestSkipped('The deliberate missing-adapter test owns the RED marker.');
        }
    }

    private function login(string $username, string $password): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'username' => $username,
            'password' => $password,
        ], $this->correlationHeaders())->assertOk();
    }

    /** @return array<string, string> */
    private function correlationHeaders(): array
    {
        return ['X-Correlation-ID' => self::CORRELATION_ID];
    }

    private function insertNotification(
        string $id,
        string $eventId,
        string $recipientUserId,
        string $sourceRecordId,
        string $createdAt,
    ): void {
        DB::table('notifications')->insert([
            'id' => $id,
            'event_id' => $eventId,
            'recipient_user_id' => $recipientUserId,
            'title' => 'تم تقديم سجل عمل',
            'source_record_id' => $sourceRecordId,
            'is_read' => false,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
