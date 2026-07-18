<?php

namespace Modules\Identity\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Identity\Features\ConsumeOrganizationPersonEvents\Handler\ConsumeOrganizationPersonEventHandler;
use Tests\TestCase;

class IdentityProvisioningConsumerTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000301';

    public function test_provisioning_event_records_inbox_request_and_high_water_without_inventing_credentials(): void
    {
        $token = $this->loginToken();
        $personId = $this->createPerson($token);
        $event = $this->organizationEvent('com.cluster.organization.identityprovisioningrequested.v1');

        $this->assertTrue($this->handler()->handle($event));
        $this->assertFalse($this->handler()->handle($event));

        $this->assertDatabaseHas('identity_inbox', ['event_id' => $event['id'], 'person_version' => 1]);
        $this->assertDatabaseHas('identity_person_event_watermarks', ['person_id' => $personId, 'last_person_version' => 1]);
        $this->assertDatabaseHas('identity_person_provisioning', [
            'person_id' => $personId,
            'person_version' => 1,
            'requested_account_status' => 'pending',
        ]);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('identity_inbox', 1);
    }

    public function test_newer_suspended_event_disables_account_revokes_sessions_and_stale_events_are_noops(): void
    {
        $token = $this->loginToken();
        $personId = $this->createPerson($token);
        $accountId = $this->createAccount($token, $personId);
        $this->withToken($token)
            ->postJson("/api/v1/identity/accounts/{$accountId}/activate", [], $this->actionHeaders('"1"', 'activate-consumer-account'))
            ->assertOk();
        DB::table('identity_sessions')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000702',
            'user_id' => $accountId,
            'token_hash' => hash('sha256', 'consumer-session'),
            'password_version' => 1,
            'issued_at' => now(),
            'expires_at' => now()->addHour(),
            'revoked_at' => null,
            'last_seen_at' => null,
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($token)
            ->patchJson("/api/v1/organization/people/{$personId}", ['status' => 'suspended'], $this->patchHeaders('"1"'))
            ->assertOk();
        $statusEvent = $this->organizationEvent('com.cluster.organization.personaccessstatuschanged.v1');
        $this->assertTrue($this->handler()->handle($statusEvent));

        $this->assertDatabaseHas('users', [
            'id' => $accountId,
            'person_version' => 2,
            'status' => 'disabled',
        ]);
        $this->assertNotNull(DB::table('identity_sessions')->where('user_id', $accountId)->value('revoked_at'));
        $this->assertDatabaseHas('identity_person_event_watermarks', ['person_id' => $personId, 'last_person_version' => 2]);

        $stale = $statusEvent;
        $stale['id'] = '018f6f7d-0c00-7000-8000-000000000811';
        $stale['data']['person_version'] = 1;
        $this->assertFalse($this->handler()->handle($stale));
        $this->assertDatabaseHas('identity_inbox', ['event_id' => $stale['id'], 'person_version' => 1]);
        $this->assertDatabaseHas('users', ['id' => $accountId, 'person_version' => 2, 'status' => 'disabled']);
    }

    public function test_person_returning_active_refreshes_snapshot_without_reactivating_disabled_account(): void
    {
        $token = $this->loginToken();
        $personId = $this->createPerson($token);
        $accountId = $this->createAccount($token, $personId);
        DB::table('users')->where('id', $accountId)->update(['status' => 'disabled']);

        $this->withToken($token)
            ->patchJson("/api/v1/organization/people/{$personId}", ['status' => 'suspended'], $this->patchHeaders('"1"'))
            ->assertOk();
        $this->handler()->handle($this->organizationEvent('com.cluster.organization.personaccessstatuschanged.v1'));
        $this->withToken($token)
            ->patchJson("/api/v1/organization/people/{$personId}", [
                'status' => 'active',
                'display_name_ar' => 'اسم العرض المحدث',
            ], $this->patchHeaders('"2"'))->assertOk();
        $this->handler()->handle($this->organizationEvent('com.cluster.organization.personaccessstatuschanged.v1'));

        $this->assertDatabaseHas('users', [
            'id' => $accountId,
            'person_version' => 3,
            'display_name_ar' => 'اسم العرض المحدث',
            'status' => 'disabled',
        ]);
    }

    public function test_profile_only_person_update_refreshes_account_snapshot(): void
    {
        $token = $this->loginToken();
        $personId = $this->createPerson($token);
        $accountId = $this->createAccount($token, $personId);

        $this->withToken($token)
            ->patchJson("/api/v1/organization/people/{$personId}", [
                'display_name_ar' => 'اسم ملف محدث',
            ], $this->patchHeaders('"1"'))->assertOk();

        $this->assertTrue($this->handler()->handle(
            $this->organizationEvent('com.cluster.organization.personupdated.v1'),
        ));
        $this->assertDatabaseHas('users', [
            'id' => $accountId,
            'person_version' => 2,
            'display_name_ar' => 'اسم ملف محدث',
            'status' => 'pending',
        ]);
    }

    public function test_missing_or_mismatched_person_reference_fails_closed_without_inbox_state(): void
    {
        $missing = $this->provisioningEvent('018f6f7d-0c00-7000-8000-000000000399', 1);
        try {
            $this->handler()->handle($missing);
            $this->fail('Missing Person reference must fail closed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('person_reference_unavailable', $exception->getMessage());
        }

        $token = $this->loginToken();
        $personId = $this->createPerson($token);
        $mismatch = $this->provisioningEvent($personId, 2);
        try {
            $this->handler()->handle($mismatch);
            $this->fail('Mismatched Person version must fail closed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('person_reference_stale', $exception->getMessage());
        }

        $this->assertDatabaseCount('identity_inbox', 0);
        $this->assertDatabaseCount('identity_person_event_watermarks', 0);
    }

    public function test_delayed_older_event_is_inboxed_without_retrying_or_applying_effects(): void
    {
        $token = $this->loginToken();
        $personId = $this->createPerson($token);
        $older = $this->organizationEvent('com.cluster.organization.identityprovisioningrequested.v1');
        $this->withToken($token)
            ->patchJson("/api/v1/organization/people/{$personId}", ['display_name_ar' => 'نسخة أحدث'], $this->patchHeaders('"1"'))
            ->assertOk();

        $this->assertFalse($this->handler()->handle($older));
        $this->assertDatabaseHas('identity_inbox', ['event_id' => $older['id'], 'person_version' => 1]);
        $this->assertDatabaseMissing('identity_person_provisioning', ['person_id' => $personId]);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_unapproved_access_context_fields_are_rejected_without_re_emission(): void
    {
        $token = $this->loginToken();
        $this->createPerson($token);
        $event = $this->organizationEvent('com.cluster.organization.identityprovisioningrequested.v1');
        $event['data']['access_context']['password'] = 'must-not-cross';

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->handler()->handle($event);
        } finally {
            $this->assertDatabaseMissing('identity_inbox', ['event_id' => $event['id']]);
            $this->assertStringNotContainsString('must-not-cross', (string) DB::table('outbox_events')
                ->where('event_type', 'com.cluster.identity.useraccountchanged.v1')->value('cloud_event'));
        }
    }

    private function handler(): ConsumeOrganizationPersonEventHandler
    {
        return $this->app->make(ConsumeOrganizationPersonEventHandler::class);
    }

    private function organizationEvent(string $eventType): array
    {
        $payload = DB::table('outbox_events')->where('event_type', $eventType)->orderByDesc('event_id')->value('cloud_event');
        $this->assertIsString($payload);

        return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    }

    private function provisioningEvent(string $personId, int $version): array
    {
        return [
            'specversion' => '1.0',
            'id' => '018f6f7d-0c00-7000-8000-000000000812',
            'source' => '/organization',
            'type' => 'com.cluster.organization.identityprovisioningrequested.v1',
            'subject' => '/organization/people/'.$personId,
            'time' => '2026-07-18T09:00:00Z',
            'datacontenttype' => 'application/json',
            'correlationid' => self::CORRELATION_ID,
            'data' => [
                'person_id' => $personId,
                'person_version' => $version,
                'requested_account_status' => 'pending',
                'access_context' => [
                    'subject_id' => '018f6f7d-0c00-7000-8000-000000000021',
                    'tenant_id' => '018f6f7d-0c00-7000-8000-000000000011',
                    'clearance' => 'confidential',
                    'correlation_id' => self::CORRELATION_ID,
                ],
                'classification' => 'confidential',
            ],
        ];
    }

    private function createPerson(string $token): string
    {
        return (string) $this->withToken($token)->postJson('/api/v1/organization/people', [
            'employee_number' => 'EMP-CONSUMER-001',
            'display_name_ar' => 'موظف المستهلك',
            'display_name_en' => 'Consumer Employee',
            'status' => 'active',
        ], $this->writeHeaders('consumer-person'))->assertCreated()->json('data.id');
    }

    private function createAccount(string $token, string $personId): string
    {
        return (string) $this->withToken($token)->postJson('/api/v1/identity/accounts', [
            'person_id' => $personId,
            'person_version' => 1,
            'username' => 'consumer.user',
        ], $this->writeHeaders('consumer-account'))->assertCreated()->json('id');
    }

    private function loginToken(): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'fixture-password-a',
        ], $this->headers())->assertOk()->json('data.access_token');
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Correlation-ID' => self::CORRELATION_ID];
    }

    /** @return array<string, string> */
    private function writeHeaders(string $key): array
    {
        return [...$this->headers(), 'Idempotency-Key' => $key];
    }

    /** @return array<string, string> */
    private function actionHeaders(string $etag, string $key): array
    {
        return [...$this->writeHeaders($key), 'If-Match' => $etag];
    }

    /** @return array<string, string> */
    private function patchHeaders(string $etag): array
    {
        return [...$this->headers(), 'If-Match' => $etag, 'Content-Type' => 'application/merge-patch+json'];
    }
}
