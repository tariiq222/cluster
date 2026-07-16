<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Notifications\Features\ListMyNotifications\Http\ListMyNotificationsController;
use Modules\WorkRecords\Features\GetAuthorizedWorkRecord\Http\GetAuthorizedWorkRecordController;
use Modules\WorkRecords\Features\ListAuthorizedWorkRecords\Http\ListAuthorizedWorkRecordsController;
use Modules\WorkRecords\Features\SubmitWorkRecord\Http\SubmitWorkRecordController;
use Tests\Support\Streams\BindsInMemoryValkeyStreamTransport;
use Tests\Support\Streams\InMemoryValkeyStreamTransport;
use Tests\TestCase;

class WalkingSkeletonE2ETest extends TestCase
{
    use BindsInMemoryValkeyStreamTransport;
    use RefreshDatabase;

    private const ACCOUNT_A_CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000001';

    private const ACCOUNT_B_CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000002';

    public function test_accounts_create_list_and_read_only_their_own_requests(): void
    {
        $this->requireHttpSlices();

        $tokenA = $this->login('fixture-account-a', 'fixture-password-a', self::ACCOUNT_A_CORRELATION_ID)
            ->assertJsonPath('data.facility', 'facility-a')
            ->json('data.access_token');
        $tokenB = $this->login('fixture-account-b', 'fixture-password-b', self::ACCOUNT_B_CORRELATION_ID)
            ->assertJsonPath('data.facility', 'facility-b')
            ->json('data.access_token');

        $this->assertIsString($tokenA);
        $this->assertIsString($tokenB);
        $this->assertNotSame($tokenA, $tokenB);

        $createdA = $this->withToken($tokenA)->postJson('/api/v1/work-records', [
            'work_definition_code' => 'request',
            'title' => 'طلب حساب أ',
            'description' => 'وصف لا يراه إلا حساب المنشأة أ.',
        ], $this->writeHeaders(self::ACCOUNT_A_CORRELATION_ID, 'a-request-001'));
        $createdA->assertCreated()->assertJsonPath('data.payload.title', 'طلب حساب أ');

        $createdB = $this->withToken($tokenB)->postJson('/api/v1/work-records', [
            'work_definition_code' => 'request',
            'title' => 'طلب حساب ب',
            'description' => 'وصف لا يراه إلا حساب المنشأة ب.',
        ], $this->writeHeaders(self::ACCOUNT_B_CORRELATION_ID, 'b-request-001'));
        $createdB->assertCreated()->assertJsonPath('data.payload.title', 'طلب حساب ب');

        $recordAId = $createdA->json('data.id');
        $recordBId = $createdB->json('data.id');

        $this->withToken($tokenA)->getJson('/api/v1/work-records', $this->correlationHeaders(self::ACCOUNT_A_CORRELATION_ID))
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $recordAId)
            ->assertJsonMissing(['id' => $recordBId, 'title' => 'طلب حساب ب']);

        $this->withToken($tokenB)->getJson('/api/v1/work-records', $this->correlationHeaders(self::ACCOUNT_B_CORRELATION_ID))
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $recordBId)
            ->assertJsonMissing(['id' => $recordAId, 'title' => 'طلب حساب أ']);

        $this->withToken($tokenA)->getJson("/api/v1/work-records/{$recordAId}", $this->correlationHeaders(self::ACCOUNT_A_CORRELATION_ID))
            ->assertOk()
            ->assertJsonPath('data.payload.description', 'وصف لا يراه إلا حساب المنشأة أ.');
        $this->withToken($tokenB)->getJson("/api/v1/work-records/{$recordBId}", $this->correlationHeaders(self::ACCOUNT_B_CORRELATION_ID))
            ->assertOk()
            ->assertJsonPath('data.payload.description', 'وصف لا يراه إلا حساب المنشأة ب.');

        $this->assertDatabaseHas('work_records', [
            'id' => $recordAId,
            'owner_facility_id' => '018f6f7d-0c00-7000-8000-000000000011',
            'creator_user_id' => '018f6f7d-0c00-7000-8000-000000000021',
            'work_type_version_id' => '0197f0e0-0000-7000-8000-000000000001',
            'classification' => 'internal',
        ]);
        $this->assertDatabaseHas('work_records', [
            'id' => $recordBId,
            'owner_facility_id' => '018f6f7d-0c00-7000-8000-000000000012',
            'creator_user_id' => '018f6f7d-0c00-7000-8000-000000000022',
            'work_type_version_id' => '0197f0e0-0000-7000-8000-000000000001',
            'classification' => 'internal',
        ]);
        $this->assertStringContainsString(
            '"correlationid":"'.self::ACCOUNT_A_CORRELATION_ID.'"',
            (string) $this->app['db']->table('outbox_events')->where('aggregate_id', $recordAId)->value('cloud_event'),
        );
    }

    public function test_cross_facility_and_absent_reads_are_byte_equivalent_and_metadata_safe(): void
    {
        $this->requireHttpSlices();

        $tokenA = $this->login('fixture-account-a', 'fixture-password-a', self::ACCOUNT_A_CORRELATION_ID)->json('data.access_token');
        $tokenB = $this->login('fixture-account-b', 'fixture-password-b', self::ACCOUNT_B_CORRELATION_ID)->json('data.access_token');

        $recordAId = $this->withToken($tokenA)->postJson('/api/v1/work-records', [
            'work_definition_code' => 'request',
            'title' => 'عنوان سري أ',
            'description' => 'وصف سري أ',
        ], $this->writeHeaders(self::ACCOUNT_A_CORRELATION_ID, 'a-request-concealment'))->json('data.id');
        $recordBId = $this->withToken($tokenB)->postJson('/api/v1/work-records', [
            'work_definition_code' => 'request',
            'title' => 'عنوان سري ب',
            'description' => 'وصف سري ب',
        ], $this->writeHeaders(self::ACCOUNT_B_CORRELATION_ID, 'b-request-concealment'))->json('data.id');

        $headers = $this->correlationHeaders('018f6f7d-0c00-7000-8000-000000000099');
        $aReadsB = $this->withToken($tokenA)->getJson("/api/v1/work-records/{$recordBId}", $headers);
        $bReadsA = $this->withToken($tokenB)->getJson("/api/v1/work-records/{$recordAId}", $headers);
        $absent = $this->withToken($tokenA)->getJson('/api/v1/work-records/018f6f7d-0c00-7000-8000-000000000010', $headers);

        foreach ([$aReadsB, $bReadsA, $absent] as $response) {
            $response->assertNotFound()
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertExactJson([
                    'type' => 'https://cluster.example/problems/work-record-unavailable',
                    'title' => 'Not Found',
                    'status' => 404,
                    'detail' => 'لا يمكنك فتح هذا الطلب أو لم يعد متاحاً.',
                ]);

            foreach (['عنوان سري', 'وصف سري', 'facility', 'owner', 'reason', 'trace', 'authorization', 'exist'] as $forbiddenMetadata) {
                $this->assertStringNotContainsString($forbiddenMetadata, $response->getContent());
            }
        }

        $this->assertSame($aReadsB->getContent(), $bReadsA->getContent());
        $this->assertSame($aReadsB->getContent(), $absent->getContent());
    }

    public function test_symmetric_api_only_flow_relays_consumes_recovers_and_lists_one_notification_per_principal(): void
    {
        $this->requireHttpSlices();
        if (! class_exists(ListMyNotificationsController::class)) {
            $this->markTestSkipped('The focused Notifications adapter RED test owns the missing marker.');
        }

        $now = 1_784_198_760_000;
        $transport = $this->bindInMemoryValkeyStreamTransport(
            new InMemoryValkeyStreamTransport(static function () use (&$now): int {
                return $now;
            }),
        );
        $tokenA = $this->login('fixture-account-a', 'fixture-password-a', self::ACCOUNT_A_CORRELATION_ID)
            ->json('data.access_token');
        $tokenB = $this->login('fixture-account-b', 'fixture-password-b', self::ACCOUNT_B_CORRELATION_ID)
            ->json('data.access_token');

        $createdA = $this->withToken($tokenA)->postJson('/api/v1/work-records', [
            'work_definition_code' => 'request',
            'title' => 'قبول إشعار حساب أ',
            'description' => 'وصف قبول أ لا يعبر حد الإشعارات.',
        ], $this->writeHeaders(self::ACCOUNT_A_CORRELATION_ID, 'notifications-a-001'))->assertCreated();
        $createdB = $this->withToken($tokenB)->postJson('/api/v1/work-records', [
            'work_definition_code' => 'request',
            'title' => 'قبول إشعار حساب ب',
            'description' => 'وصف قبول ب لا يعبر حد الإشعارات.',
        ], $this->writeHeaders(self::ACCOUNT_B_CORRELATION_ID, 'notifications-b-001'))->assertCreated();
        $recordAId = $createdA->json('data.id');
        $recordBId = $createdB->json('data.id');

        $this->artisan('work-records:relay-pending --once')->assertSuccessful();
        $this->assertCount(2, $transport->streamEntries('platform.work-record.submitted.v1'));

        $transport->failNextAck();
        $this->artisan('notifications:consume-work-record-submitted --once --consumer=acceptance-a')->assertFailed();
        $this->assertDatabaseCount('notification_inbox', 1);
        $this->assertDatabaseCount('notifications', 1);

        $now += 60_001;
        $this->artisan('notifications:consume-work-record-submitted --once --consumer=acceptance-b')->assertSuccessful();
        $this->artisan('notifications:consume-work-record-submitted --once --consumer=acceptance-b')->assertSuccessful();
        $this->assertDatabaseCount('notification_inbox', 2);
        $this->assertDatabaseCount('notifications', 2);

        $notificationAId = DB::table('notifications')->where('recipient_user_id', '018f6f7d-0c00-7000-8000-000000000021')->value('id');
        $notificationBId = DB::table('notifications')->where('recipient_user_id', '018f6f7d-0c00-7000-8000-000000000022')->value('id');
        $listA = $this->withToken($tokenA)->getJson('/api/v1/notifications', $this->correlationHeaders(self::ACCOUNT_A_CORRELATION_ID));
        $listB = $this->withToken($tokenB)->getJson('/api/v1/notifications', $this->correlationHeaders(self::ACCOUNT_B_CORRELATION_ID));

        $listA->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $notificationAId)
            ->assertJsonPath('items.0.source.record_id', $recordAId)
            ->assertJsonMissing(['id' => $notificationBId, 'record_id' => $recordBId]);
        $listB->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $notificationBId)
            ->assertJsonPath('items.0.source.record_id', $recordBId)
            ->assertJsonMissing(['id' => $notificationAId, 'record_id' => $recordAId]);

        foreach ([$listA, $listB] as $response) {
            $this->assertSame(['items', 'next_cursor'], array_keys($response->json()));
            $this->assertSame(['id', 'title', 'source', 'is_read', 'created_at'], array_keys($response->json('items.0')));
            foreach (['payload', 'description', 'facility', 'reason', 'trace', 'authorization', 'access_token'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $response->getContent());
            }
        }

        $headers = $this->correlationHeaders('018f6f7d-0c00-7000-8000-000000000099');
        $aReadsB = $this->withToken($tokenA)->getJson("/api/v1/work-records/{$recordBId}", $headers);
        $bReadsA = $this->withToken($tokenB)->getJson("/api/v1/work-records/{$recordAId}", $headers);
        $this->assertSame($aReadsB->assertNotFound()->getContent(), $bReadsA->assertNotFound()->getContent());
    }

    private function requireHttpSlices(): void
    {
        if (! class_exists(SubmitWorkRecordController::class)
            || ! class_exists(GetAuthorizedWorkRecordController::class)
            || ! class_exists(ListAuthorizedWorkRecordsController::class)) {
            $this->markTestSkipped('WorkRecord HTTP slices are specified by the focused adapter RED test.');
        }
    }

    private function login(string $username, string $password, string $correlationId): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'username' => $username,
            'password' => $password,
        ], $this->correlationHeaders($correlationId))->assertOk();
    }

    /** @return array<string, string> */
    private function correlationHeaders(string $correlationId): array
    {
        return ['X-Correlation-ID' => $correlationId];
    }

    /** @return array<string, string> */
    private function writeHeaders(string $correlationId, string $idempotencyKey): array
    {
        return [
            ...$this->correlationHeaders($correlationId),
            'Idempotency-Key' => $idempotencyKey,
        ];
    }
}
