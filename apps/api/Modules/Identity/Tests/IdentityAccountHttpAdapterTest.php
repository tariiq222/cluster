<?php

namespace Modules\Identity\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Identity\Features\UserAccount\Handler\UserAccountHandler;
use Modules\Identity\Http\IdentityApi;
use Tests\TestCase;

class IdentityAccountHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000301';

    public function test_pending_account_is_created_from_an_exact_person_reference_with_stable_replay(): void
    {
        $token = $this->loginToken();
        $personId = $this->createPerson($token);
        $body = ['person_id' => $personId, 'person_version' => 1, 'username' => 'Employee.One'];

        $first = $this->withToken($token)
            ->postJson('/api/v1/identity/accounts', $body, $this->writeHeaders('account-create'))
            ->assertCreated()
            ->assertHeader('X-Correlation-ID', self::CORRELATION_ID)
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('username', 'employee.one')
            ->assertJsonPath('person_id', $personId)
            ->assertJsonPath('person_version', 1)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('display_name_ar', 'موظف الهوية')
            ->assertJsonPath('display_name_en', 'Identity Employee')
            ->assertJsonPath('must_change_password', true)
            ->assertJsonPath('password_version', 1)
            ->assertJsonPath('locked_until', null);
        $accountId = $first->json('id');
        $this->assertIsString($accountId);
        $this->assertDatabaseHas('users', [
            'id' => $accountId,
            'person_id' => $personId,
            'person_version' => 1,
            'username' => 'employee.one',
            'status' => 'pending',
            'lock_version' => 1,
        ]);
        $this->assertDatabaseHas('identity_person_account_claims', [
            'person_id' => $personId,
            'account_id' => $accountId,
        ]);
        $storedReplay = (string) DB::table('identity_idempotency_keys')->value('response_payload');
        $this->assertStringNotContainsString('employee.one', $storedReplay);
        $this->assertStringNotContainsString('موظف الهوية', $storedReplay);
        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $accountId,
            'event_type' => 'com.cluster.identity.useraccountcreated.v1',
        ]);
        $identityEvent = (string) DB::table('outbox_events')
            ->where('event_type', 'com.cluster.identity.useraccountcreated.v1')
            ->value('cloud_event');
        $this->assertStringNotContainsString('employee.one', $identityEvent);
        $this->assertStringNotContainsString('موظف الهوية', $identityEvent);

        $this->withToken($token)
            ->postJson("/api/v1/identity/accounts/{$accountId}/activate", [], $this->actionHeaders('"1"', 'activate-account'))
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('status', 'active');
        $this->withToken($token)
            ->postJson('/api/v1/identity/accounts', $body, $this->writeHeaders('account-create'))
            ->assertCreated()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('id', $accountId)
            ->assertJsonPath('status', 'pending');
        $this->withToken($token)
            ->postJson('/api/v1/identity/accounts', [...$body, 'username' => 'different'], $this->writeHeaders('account-create'))
            ->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/idempotency-conflict');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('identity_person_account_claims', 1);
    }

    public function test_account_creation_fails_closed_for_missing_stale_or_inactive_person_reference(): void
    {
        $token = $this->loginToken();
        $missingId = '018f6f7d-0c00-7000-8000-000000000399';
        $this->withToken($token)->postJson('/api/v1/identity/accounts', [
            'person_id' => $missingId,
            'person_version' => 1,
            'username' => 'missing.person',
        ], $this->writeHeaders('missing-person'))->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/person-reference-unavailable');

        $personId = $this->createPerson($token);
        $this->withToken($token)->postJson('/api/v1/identity/accounts', [
            'person_id' => $personId,
            'person_version' => 2,
            'username' => 'stale.person',
        ], $this->writeHeaders('stale-person'))->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/person-reference-stale');
        $this->withToken($token)
            ->patchJson("/api/v1/organization/people/{$personId}", ['status' => 'suspended'], $this->patchHeaders('"1"'))
            ->assertOk();
        $this->withToken($token)->postJson('/api/v1/identity/accounts', [
            'person_id' => $personId,
            'person_version' => 2,
            'username' => 'inactive.person',
        ], $this->writeHeaders('inactive-person'))->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/person-reference-inactive');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('identity_idempotency_keys', 0);
    }

    public function test_account_lifecycle_enforces_etag_revokes_sessions_and_releases_person_on_archive(): void
    {
        $token = $this->loginToken();
        $personId = $this->createPerson($token);
        $accountId = $this->createAccount($token, $personId, 'lifecycle.user');

        $this->withToken($token)
            ->postJson("/api/v1/identity/accounts/{$accountId}/activate", [], $this->actionHeaders('"1"', 'activate'))
            ->assertOk()->assertHeader('ETag', '"2"')->assertJsonPath('status', 'active');
        $this->withToken($token)
            ->postJson("/api/v1/identity/accounts/{$accountId}/disable", [], $this->actionHeaders('"1"', 'stale-disable'))
            ->assertStatus(412);

        DB::table('identity_sessions')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000701',
            'user_id' => $accountId,
            'token_hash' => hash('sha256', 'identity-session'),
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
            ->postJson("/api/v1/identity/accounts/{$accountId}/force-password-change", ['reason' => 'security review'], $this->actionHeaders('"2"', 'force-change'))
            ->assertOk()->assertHeader('ETag', '"3"')->assertJsonPath('must_change_password', true)->assertJsonPath('password_version', 2);
        $this->assertTrue(Schema::hasTable('identity_sessions'));
        $this->assertFalse(Schema::hasTable('sessions'));
        $this->assertNotNull(DB::table('identity_sessions')->where('user_id', $accountId)->value('revoked_at'));

        $this->withToken($token)
            ->postJson("/api/v1/identity/accounts/{$accountId}/disable", ['reason' => 'administrative'], $this->actionHeaders('"3"', 'disable'))
            ->assertOk()->assertHeader('ETag', '"4"')->assertJsonPath('status', 'disabled');
        $this->withToken($token)
            ->postJson("/api/v1/identity/accounts/{$accountId}/activate", [], $this->actionHeaders('"1"', 'activate'))
            ->assertOk()->assertHeader('ETag', '"2"')->assertJsonPath('status', 'active');
        $this->withToken($token)
            ->postJson("/api/v1/identity/accounts/{$accountId}/activate", ['reason' => 'different'], $this->actionHeaders('"1"', 'activate'))
            ->assertConflict()->assertJsonPath('type', 'https://cluster.example/problems/idempotency-conflict');
        $this->withToken($token)
            ->postJson("/api/v1/identity/accounts/{$accountId}/activate", ['reason' => 'approved'], $this->actionHeaders('"4"', 'reactivate'))
            ->assertOk()->assertHeader('ETag', '"5"')->assertJsonPath('status', 'active');
        $this->withToken($token)
            ->postJson("/api/v1/identity/accounts/{$accountId}/archive", ['reason' => 'replacement'], $this->actionHeaders('"5"', 'archive'))
            ->assertOk()->assertHeader('ETag', '"6"')->assertJsonPath('status', 'archived');
        $this->assertDatabaseMissing('identity_person_account_claims', ['person_id' => $personId]);

        $replacement = $this->withToken($token)->postJson('/api/v1/identity/accounts', [
            'person_id' => $personId,
            'person_version' => 1,
            'username' => 'replacement.user',
        ], $this->writeHeaders('replacement'))->assertCreated();
        $this->assertNotSame($accountId, $replacement->json('id'));
    }

    public function test_duplicate_username_or_live_person_claim_is_rejected(): void
    {
        $token = $this->loginToken();
        $personA = $this->createPerson($token, 'EMP-ID-A', 'person-a');
        $personB = $this->createPerson($token, 'EMP-ID-B', 'person-b');
        $this->createAccount($token, $personA, 'duplicate.user', 'account-a');

        $this->withToken($token)->postJson('/api/v1/identity/accounts', [
            'person_id' => $personB,
            'person_version' => 1,
            'username' => 'DUPLICATE.USER',
        ], $this->writeHeaders('duplicate-username'))->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/username-already-exists');
        $this->withToken($token)->postJson('/api/v1/identity/accounts', [
            'person_id' => $personA,
            'person_version' => 1,
            'username' => 'another.user',
        ], $this->writeHeaders('duplicate-person'))->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/person-account-already-exists');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_account_transition_rolls_back_when_outbox_insert_fails(): void
    {
        $token = $this->loginToken();
        $personId = $this->createPerson($token);
        $accountId = $this->createAccount($token, $personId, 'rollback.user');
        $duplicateEventId = (string) DB::table('outbox_events')->value('event_id');
        try {
            $this->app->make(UserAccountHandler::class)->transition(
                $accountId,
                'activate',
                1,
                null,
                [
                    'principal_id' => '018f6f7d-0c00-7000-8000-000000000021',
                    'operation' => 'rollback-transition',
                    'key_hash' => hash('sha256', 'rollback-transition'),
                    'request_hash' => hash('sha256', 'rollback-transition'),
                ],
                fn (): array => [
                    'id' => $duplicateEventId,
                    'type' => 'com.cluster.identity.useraccountchanged.v1',
                    'time' => '2026-07-18T09:00:00Z',
                ],
            );
            $this->fail('The duplicate Outbox event must fail the transaction.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', (string) $exception->getCode());
        }

        $this->assertDatabaseHas('users', ['id' => $accountId, 'status' => 'pending', 'lock_version' => 1]);
        $this->assertDatabaseMissing('identity_idempotency_keys', ['operation' => 'rollback-transition']);
    }

    public function test_account_reads_are_authorized_and_cursor_paginated(): void
    {
        $admin = $this->loginToken();
        $other = $this->loginToken('fixture-account-b', 'fixture-password-b');
        foreach (range(1, 3) as $number) {
            $personId = $this->createPerson($admin, "EMP-PAGE-{$number}", "page-person-{$number}");
            $this->createAccount($admin, $personId, "page.user.{$number}", "page-account-{$number}");
        }

        $first = $this->withToken($admin)->getJson('/api/v1/identity/accounts?limit=2', $this->headers())
            ->assertOk()->assertJsonCount(2, 'items')->assertHeader('Link');
        $cursor = $first->json('next_cursor');
        $this->assertIsString($cursor);
        $accountId = $first->json('items.0.id');
        $this->withToken($admin)->getJson("/api/v1/identity/accounts/{$accountId}", $this->headers())
            ->assertOk()->assertHeader('ETag', '"1"');
        $this->withToken($admin)->getJson('/api/v1/identity/accounts?limit=2&cursor='.rawurlencode($cursor), $this->headers())
            ->assertOk()->assertJsonCount(1, 'items')->assertHeaderMissing('Link');
        $this->withToken($admin)->getJson('/api/v1/identity/accounts?limit=3&cursor='.rawurlencode($cursor), $this->headers())
            ->assertBadRequest();
        $this->withToken($other)->getJson('/api/v1/identity/accounts', $this->headers())->assertForbidden();
        $this->withHeader('Authorization', '')->getJson('/api/v1/identity/accounts', $this->headers())->assertUnauthorized();
        $this->getJson('/api/v1/identity/accounts')->assertBadRequest()->assertHeader('X-Correlation-ID');
    }

    private function createPerson(
        string $token,
        string $employeeNumber = 'EMP-IDENTITY-001',
        string $key = 'identity-person',
    ): string {
        return (string) $this->withToken($token)->postJson('/api/v1/organization/people', [
            'employee_number' => $employeeNumber,
            'display_name_ar' => 'موظف الهوية',
            'display_name_en' => 'Identity Employee',
            'status' => 'active',
        ], $this->writeHeaders($key))->assertCreated()->json('data.id');
    }

    public function test_identity_cloud_events_do_not_grant_bootstrap_admin_by_default(): void
    {
        $event = IdentityApi::cloudEvent(
            'com.cluster.identity.useraccountcreated.v1',
            '/identity/accounts/018f6f7d-0c00-7000-8000-000000000501',
            self::CORRELATION_ID,
            ['user_id' => '018f6f7d-0c00-7000-8000-000000000502'],
            ['account_id' => '018f6f7d-0c00-7000-8000-000000000501'],
        );

        $this->assertSame([], $event['data']['access_context']['roles']);
        $this->assertNull($event['data']['access_context']['tenant_id']);
        $this->assertNotContains('bootstrap_admin', $event['data']['access_context']['roles']);
    }

    private function createAccount(
        string $token,
        string $personId,
        string $username,
        string $key = 'identity-account',
    ): string {
        return (string) $this->withToken($token)->postJson('/api/v1/identity/accounts', [
            'person_id' => $personId,
            'person_version' => 1,
            'username' => $username,
        ], $this->writeHeaders($key))->assertCreated()->json('id');
    }

    private function loginToken(
        string $username = 'fixture-account-a',
        string $password = 'fixture-password-a',
    ): string {
        return (string) $this->postJson('/api/v1/auth/login', [
            'username' => $username,
            'password' => $password,
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
