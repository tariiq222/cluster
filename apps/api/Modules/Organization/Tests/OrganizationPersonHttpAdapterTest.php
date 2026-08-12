<?php

namespace Modules\Organization\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Organization\Features\Person\Handler\PersonHandler;
use Tests\TestCase;

class OrganizationPersonHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000301';

    public function test_person_is_registered_listed_read_and_exposed_through_the_minimal_reference(): void
    {
        $token = $this->loginToken();
        $person = $this->withToken($token)
            ->postJson('/api/v1/organization/people', $this->personBody(), $this->writeHeaders('person-create'))
            ->assertCreated()
            ->assertHeader('X-Correlation-ID', self::CORRELATION_ID)
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.employee_number', 'EMP-001')
            ->assertJsonPath('data.display_name_ar', 'موظف الاختبار')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.person_version', 1);
        $personId = $person->json('data.id');
        $this->assertIsString($personId);
        $this->assignPersonToFixtureOrganization($personId);

        $this->assertTrue(Schema::hasColumns('people', [
            'national_id_ciphertext',
            'national_id_lookup_hash',
            'primary_email_ciphertext',
            'primary_phone_ciphertext',
        ]));
        $this->assertFalse(Schema::hasColumn('people', 'national_id'));
        $this->assertFalse(Schema::hasColumn('people', 'primary_email'));
        $this->assertFalse(Schema::hasColumn('people', 'primary_phone'));
        $this->assertDatabaseHas('people', [
            'id' => $personId,
            'employee_number' => 'EMP-001',
            'status' => 'active',
            'person_version' => 1,
        ]);

        $this->withToken($token)
            ->getJson("/api/v1/organization/people/{$personId}", $this->headers())
            ->assertOk()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.id', $personId)
            ->assertJsonMissingPath('data.national_id');
        $this->withToken($token)
            ->getJson("/api/v1/organization/people/{$personId}/reference", $this->headers())
            ->assertOk()
            ->assertExactJson([
                'person_id' => $personId,
                'person_version' => 1,
                'status' => 'active',
                'display_name_ar' => 'موظف الاختبار',
                'display_name_en' => 'Test Employee',
            ]);
        $this->withToken($token)
            ->getJson('/api/v1/organization/people', $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $personId);

        $events = DB::table('outbox_events')->where('aggregate_id', $personId)->orderBy('event_type')->get();
        $this->assertCount(2, $events);
        $this->assertSame([
            'com.cluster.organization.identityprovisioningrequested.v1',
            'com.cluster.organization.personregistered.v1',
        ], $events->pluck('event_type')->all());
        foreach ($events as $eventRow) {
            $event = json_decode((string) $eventRow->cloud_event, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('confidential', $event['data']['classification']);
            $encoded = json_encode($event, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('EMP-001', $encoded);
            $this->assertStringNotContainsString('موظف الاختبار', $encoded);
            $this->assertStringNotContainsString('Test Employee', $encoded);
            $this->assertStringNotContainsString('national_id', $encoded);
            $this->assertStringNotContainsString('primary_email', $encoded);
            $this->assertStringNotContainsString('primary_phone', $encoded);
        }
    }

    public function test_person_create_replays_the_original_snapshot_and_rejects_duplicates(): void
    {
        $token = $this->loginToken();
        $body = $this->personBody();
        $first = $this->withToken($token)
            ->postJson('/api/v1/organization/people', $body, $this->writeHeaders('stable-person'))
            ->assertCreated();
        $personId = (string) $first->json('data.id');
        $this->assignPersonToFixtureOrganization($personId);
        $storedReplay = DB::table('organization_idempotency_keys')->value('response_payload');
        $this->assertIsString($storedReplay);
        $this->assertSame($first->json('data'), json_decode($storedReplay, true, 32, JSON_THROW_ON_ERROR));

        $this->withToken($token)
            ->patchJson("/api/v1/organization/people/{$personId}", ['display_name_ar' => 'اسم محدث'], $this->patchHeaders('"1"'))
            ->assertOk()
            ->assertHeader('ETag', '"2"');
        $this->withToken($token)
            ->postJson('/api/v1/organization/people', $body, $this->writeHeaders('stable-person'))
            ->assertCreated()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.id', $personId)
            ->assertJsonPath('data.display_name_ar', 'موظف الاختبار')
            ->assertJsonPath('data.person_version', 1);
        $this->withToken($token)
            ->postJson('/api/v1/organization/people', [...$body, 'display_name_ar' => 'طلب مختلف'], $this->writeHeaders('stable-person'))
            ->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/idempotency-conflict');
        $this->withToken($token)
            ->postJson('/api/v1/organization/people', $body, $this->writeHeaders('duplicate-person'))
            ->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/person-already-exists');

        $this->assertDatabaseCount('people', 1);
        $this->assertDatabaseCount('organization_idempotency_keys', 1);
    }

    public function test_person_create_replays_a_legacy_encrypted_snapshot_from_before_the_plain_json_cutover(): void
    {
        $token = $this->loginToken();
        $body = $this->personBody();
        $first = $this->withToken($token)
            ->postJson('/api/v1/organization/people', $body, $this->writeHeaders('legacy-person'))
            ->assertCreated();
        $personId = (string) $first->json('data.id');

        // Rewrite the stored snapshot into the pre-cutover format:
        // json_encode(Crypt::encryptString(json_encode($person))).
        DB::table('organization_idempotency_keys')->update([
            'response_payload' => json_encode(
                Crypt::encryptString(json_encode($first->json('data'), JSON_THROW_ON_ERROR)),
                JSON_THROW_ON_ERROR,
            ),
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/organization/people', $body, $this->writeHeaders('legacy-person'))
            ->assertCreated()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.id', $personId)
            ->assertJsonPath('data.display_name_ar', 'موظف الاختبار')
            ->assertJsonPath('data.person_version', 1);

        $this->assertDatabaseCount('people', 1);
        $this->assertDatabaseCount('organization_idempotency_keys', 1);
    }

    public function test_person_update_requires_current_etag_and_publishes_versioned_status_change(): void
    {
        $token = $this->loginToken();
        $personId = $this->createPerson($token);

        $this->withToken($token)
            ->patchJson("/api/v1/organization/people/{$personId}", ['status' => 'suspended'], $this->headers())
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-if-match');
        $this->withToken($token)
            ->patchJson("/api/v1/organization/people/{$personId}", ['status' => 'suspended'], $this->patchHeaders('"2"'))
            ->assertStatus(412)
            ->assertJsonPath('type', 'https://cluster.example/problems/precondition-failed');
        $this->withToken($token)
            ->patchJson("/api/v1/organization/people/{$personId}", [
                'display_name_en' => null,
                'status' => 'suspended',
            ], $this->patchHeaders('"1"'))
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.person_version', 2)
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.display_name_en', null);
        $this->withToken($token)
            ->patchJson("/api/v1/organization/people/{$personId}", ['display_name_ar' => 'كتابة متأخرة'], $this->patchHeaders('"1"'))
            ->assertStatus(412);

        $this->assertDatabaseHas('people', ['id' => $personId, 'person_version' => 2, 'status' => 'suspended']);
        $this->assertSame(1, DB::table('outbox_events')->where('aggregate_id', $personId)
            ->where('event_type', 'com.cluster.organization.personupdated.v1')->count());
        $statusEvent = DB::table('outbox_events')->where('aggregate_id', $personId)
            ->where('event_type', 'com.cluster.organization.personaccessstatuschanged.v1')->first();
        $this->assertNotNull($statusEvent);
        $payload = json_decode((string) $statusEvent->cloud_event, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($personId, $payload['data']['person_id']);
        $this->assertSame(2, $payload['data']['person_version']);
        $this->assertSame('suspended', $payload['data']['access_status']);
        $this->assertSame('confidential', $payload['data']['classification']);
    }

    public function test_person_requests_fail_closed_and_invalid_inputs_write_nothing(): void
    {
        $admin = $this->loginToken();
        $other = $this->loginToken('fixture-account-b', 'fixture-password-b');

        $this->postJson('/api/v1/organization/people', $this->personBody(), $this->writeHeaders('anonymous'))
            ->assertUnauthorized();
        $this->withToken($other)
            ->postJson('/api/v1/organization/people', $this->personBody(), $this->writeHeaders('denied'))
            ->assertForbidden();
        $this->withToken($admin)
            ->postJson('/api/v1/organization/people', [...$this->personBody(), 'national_id' => '1234567890'], $this->writeHeaders('uncontracted-pii'))
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-person');
        $this->withToken($admin)
            ->getJson('/api/v1/organization/people/not-a-uuid', $this->headers())
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-person-id');
        $this->withToken($admin)
            ->getJson('/api/v1/organization/people/018f6f7d-0c00-7000-8000-000000000399', $this->headers())
            ->assertNotFound();

        $this->assertDatabaseCount('people', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_person_list_uses_principal_and_limit_bound_opaque_cursor_pagination(): void
    {
        $token = $this->loginToken();
        foreach (range(1, 3) as $number) {
            $personId = (string) $this->withToken($token)->postJson('/api/v1/organization/people', [
                'employee_number' => "EMP-00{$number}",
                'display_name_ar' => "موظف {$number}",
                'status' => 'active',
            ], $this->writeHeaders("person-page-{$number}"))->assertCreated()->json('data.id');
            $this->assignPersonToFixtureOrganization($personId);
        }

        $first = $this->withToken($token)
            ->getJson('/api/v1/organization/people?limit=2', $this->headers())
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertHeader('Link');
        $cursor = $first->json('next_cursor');
        $this->assertIsString($cursor);

        $this->withToken($token)
            ->getJson('/api/v1/organization/people?limit=2&cursor='.rawurlencode($cursor), $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('next_cursor', null)
            ->assertHeaderMissing('Link');
        $this->withToken($token)
            ->getJson('/api/v1/organization/people?limit=3&cursor='.rawurlencode($cursor), $this->headers())
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-pagination');
    }

    public function test_person_update_rolls_back_when_any_outbox_event_fails(): void
    {
        $token = $this->loginToken();
        $personId = $this->createPerson($token);
        $eventId = (string) DB::table('outbox_events')->value('event_id');

        try {
            $this->app->make(PersonHandler::class)->update(
                $personId,
                1,
                ['display_name_ar' => 'اسم يجب أن يلف'],
                fn (array $person, string $previousStatus): array => [[
                    'id' => $eventId,
                    'type' => 'com.cluster.organization.personupdated.v1',
                    'time' => '2026-07-18T00:00:00.000Z',
                    'data' => ['person' => $person, 'previous_status' => $previousStatus],
                ]],
            );
            $this->fail('The duplicate outbox event must fail the transaction.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', (string) $exception->getCode());
        }

        $this->assertDatabaseHas('people', [
            'id' => $personId,
            'display_name_ar' => 'موظف الاختبار',
            'person_version' => 1,
        ]);
        $this->assertDatabaseCount('outbox_events', 2);
    }

    /** @return array<string, string> */
    private function personBody(): array
    {
        return [
            'employee_number' => 'EMP-001',
            'display_name_ar' => 'موظف الاختبار',
            'display_name_en' => 'Test Employee',
            'status' => 'active',
        ];
    }

    private function createPerson(string $token): string
    {
        $personId = (string) $this->withToken($token)
            ->postJson('/api/v1/organization/people', $this->personBody(), $this->writeHeaders('person-for-update'))
            ->assertCreated()
            ->json('data.id');
        $this->assignPersonToFixtureOrganization($personId);

        return $personId;
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
    private function patchHeaders(string $etag): array
    {
        return [
            ...$this->headers(),
            'If-Match' => $etag,
            'Content-Type' => 'application/merge-patch+json',
        ];
    }
}
