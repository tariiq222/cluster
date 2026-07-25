<?php

namespace Modules\Notifications\Features\ListMyNotifications\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MarkNotificationReadControllerTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000031';

    private const USER_A_ID = '018f6f7d-0c00-7000-8000-000000000021';

    private const NOTIFICATION_ID = '018f6f7d-0c00-7000-8000-000000000811';

    public function test_recipient_can_mark_notification_read_and_retry_returns_the_same_response(): void
    {
        $tokenA = $this->login('fixture-account-a', 'fixture-password-a')->json('data.access_token');
        $createdAt = '2026-07-16 09:00:00';
        $this->insertNotification(self::NOTIFICATION_ID, self::USER_A_ID, $createdAt);

        $headers = $this->correlationHeaders();
        $first = $this->withToken($tokenA)->postJson($this->readEndpoint(self::NOTIFICATION_ID), [], $headers);
        $first->assertOk()
            ->assertHeader('X-Correlation-ID', self::CORRELATION_ID)
            ->assertExactJson(['data' => ['id' => self::NOTIFICATION_ID, 'is_read' => true]]);

        $row = DB::table('notifications')->where('id', self::NOTIFICATION_ID)->first();
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->is_read);

        // A client retrying the same request — with or without the same
        // Idempotency-Key — must observe the same response. The handler
        // performs a single conditional UPDATE that is naturally idempotent
        // at the SQL level (the row stays read), so no replay record is
        // needed and no Idempotency-Key header is required to drive the
        // happy path. A second POST must therefore be safe.
        $second = $this->withToken($tokenA)->postJson($this->readEndpoint(self::NOTIFICATION_ID), [], $headers);
        $second->assertOk()
            ->assertHeader('X-Correlation-ID', self::CORRELATION_ID)
            ->assertExactJson($first->json());

        $rowAfter = DB::table('notifications')->where('id', self::NOTIFICATION_ID)->first();
        $this->assertNotNull($rowAfter);
        $this->assertTrue((bool) $rowAfter->is_read);
    }

    public function test_unauthenticated_request_is_rejected_with_401_problem(): void
    {
        $response = $this->postJson($this->readEndpoint(self::NOTIFICATION_ID), [], $this->correlationHeaders());
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

    public function test_unknown_notification_returns_404_problem_for_authenticated_recipient(): void
    {
        $tokenA = $this->login('fixture-account-a', 'fixture-password-a')->json('data.access_token');
        $unknownId = '018f6f7d-0c00-7000-8000-000000000899';

        $response = $this->withToken($tokenA)->postJson($this->readEndpoint($unknownId), [], $this->correlationHeaders());
        $response->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('X-Correlation-ID', self::CORRELATION_ID)
            ->assertExactJson([
                'type' => 'https://cluster.example/problems/resource-not-found',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => 'The notification is not available.',
            ]);
    }

    public function test_recipient_cannot_mark_another_users_notification(): void
    {
        $tokenB = $this->login('fixture-account-b', 'fixture-password-b')->json('data.access_token');
        $this->insertNotification(self::NOTIFICATION_ID, self::USER_A_ID, '2026-07-16 09:00:00');

        $response = $this->withToken($tokenB)->postJson($this->readEndpoint(self::NOTIFICATION_ID), [], $this->correlationHeaders());
        $response->assertNotFound()
            ->assertHeader('X-Correlation-ID', self::CORRELATION_ID)
            ->assertExactJson([
                'type' => 'https://cluster.example/problems/resource-not-found',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => 'The notification is not available.',
            ]);

        $row = DB::table('notifications')->where('id', self::NOTIFICATION_ID)->first();
        $this->assertFalse((bool) $row->is_read);
    }

    private function readEndpoint(string $notificationId): string
    {
        return '/api/v1/notifications/'.$notificationId.'/read';
    }

    /** @return array<string, string> */
    private function correlationHeaders(): array
    {
        return ['X-Correlation-ID' => self::CORRELATION_ID];
    }

    private function login(string $username, string $password): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'username' => $username,
            'password' => $password,
        ], $this->correlationHeaders())->assertOk();
    }

    private function insertNotification(string $id, string $recipientUserId, string $createdAt): void
    {
        DB::table('notifications')->insert([
            'id' => $id,
            'event_id' => '018f6f7d-0c00-7000-8000-000000000912',
            'recipient_user_id' => $recipientUserId,
            'title' => 'تم تقديم سجل عمل',
            'source_record_id' => '018f6f7d-0c00-7000-8000-000000000913',
            'is_read' => false,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
