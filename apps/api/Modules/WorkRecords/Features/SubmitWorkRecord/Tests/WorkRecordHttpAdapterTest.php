<?php

namespace Modules\WorkRecords\Features\SubmitWorkRecord\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\WorkDefinitions\Contracts\ResolvePublishedRequestFixture;
use Modules\WorkRecords\Features\GetAuthorizedWorkRecord\Http\GetAuthorizedWorkRecordController;
use Modules\WorkRecords\Features\ListAuthorizedWorkRecords\Http\ListAuthorizedWorkRecordsController;
use Modules\WorkRecords\Features\SubmitWorkRecord\Http\SubmitWorkRecordController;
use Tests\TestCase;

class WorkRecordHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000201';

    public function test_work_record_http_slices_are_present(): void
    {
        $this->assertTrue(
            class_exists(SubmitWorkRecordController::class)
                && class_exists(GetAuthorizedWorkRecordController::class)
                && class_exists(ListAuthorizedWorkRecordsController::class),
            'MISSING_WORK_RECORD_HTTP_SLICES',
        );
    }

    public function test_unknown_or_missing_bearer_credentials_are_rejected(): void
    {
        $this->requireHttpSlices();

        $this->getJson('/api/v1/work-records', $this->headers())->assertUnauthorized();
        $this->withToken('unknown-development-token')
            ->getJson('/api/v1/work-records', $this->headers())
            ->assertUnauthorized();
    }

    public function test_headers_and_body_are_validated_before_source_or_outbox_persistence(): void
    {
        $this->requireHttpSlices();
        $token = $this->loginToken();
        $validBody = [
            'work_definition_code' => 'request',
            'title' => 'عنوان صالح',
            'description' => 'وصف صالح',
        ];

        $this->withToken($token)->postJson('/api/v1/work-records', $validBody)->assertBadRequest();
        $this->withToken($token)->postJson('/api/v1/work-records', $validBody, $this->headers())->assertBadRequest();
        $this->withToken($token)->postJson('/api/v1/work-records', [
            'work_definition_code' => 'request',
            'title' => '',
            'description' => '',
        ], $this->writeHeaders())->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/work-records', [
            ...$validBody,
            'owner_facility_id' => '018f6f7d-0c00-7000-8000-000000000012',
        ], $this->writeHeaders())->assertUnprocessable();

        $this->assertDatabaseCount('work_records', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_create_derives_ownership_version_classification_and_event_correlation_on_the_server(): void
    {
        $this->requireHttpSlices();
        $token = $this->loginToken();

        $response = $this->withToken($token)->postJson('/api/v1/work-records', [
            'work_definition_code' => 'request',
            'title' => 'عنوان موثوق',
            'description' => 'وصف موثوق',
        ], $this->writeHeaders());

        $response->assertCreated()
            ->assertJsonPath('data.owner.facility_id', '018f6f7d-0c00-7000-8000-000000000011')
            ->assertJsonPath('data.owner.user_id', '018f6f7d-0c00-7000-8000-000000000021')
            ->assertJsonPath('data.work_type_version_id', '0197f0e0-0000-7000-8000-000000000001')
            ->assertJsonPath('data.classification', 'internal');

        $event = (string) $this->app['db']->table('outbox_events')->value('cloud_event');
        $this->assertStringContainsString('"correlationid":"'.self::CORRELATION_ID.'"', $event);
    }

    public function test_work_definitions_publishes_only_the_resolved_request_fixture_value(): void
    {
        $fixture = $this->app->make(ResolvePublishedRequestFixture::class)->resolve();

        $this->assertSame([
            'version_id' => '0197f0e0-0000-7000-8000-000000000001',
            'code' => 'request',
            'fields' => ['title', 'description'],
        ], $fixture);
    }

    public function test_submit_carries_the_published_definitions_field_policy_key_onto_the_record(): void
    {
        $token = $this->loginToken();
        $now = now();

        // A published definition with a field policy key must surface that
        // key on records created against it (masking/hiding depends on it).
        $definitionId = (string) Str::uuid7();
        $versionId = (string) Str::uuid7();
        DB::table('work_definitions')->insert([
            'id' => $definitionId,
            'code' => 'policy-carry',
            'name' => 'تعريف بسياسة',
            'description' => 'Policy definition',
            'default_classification' => 'internal',
            'created_by_user_id' => '018f6f7d-0c00-7000-8000-000000000021',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('work_definition_versions')->insert([
            'id' => $versionId,
            'work_definition_id' => $definitionId,
            'version_number' => 1,
            'status' => 'published',
            'schema_document' => json_encode([
                'type' => 'object',
                'properties' => ['title' => ['type' => 'string'], 'description' => ['type' => 'string']],
            ], JSON_THROW_ON_ERROR),
            'field_policy_key' => 'work_record.classified',
            'schema_hash' => hash('sha256', 'policy-carry-v1'),
            'created_by_user_id' => '018f6f7d-0c00-7000-8000-000000000021',
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $created = $this->withToken($token)->postJson('/api/v1/work-records', [
            'work_definition_code' => 'policy-carry',
            'title' => 'طلب بسياسة',
            'description' => 'وصف',
        ], $this->writeHeaders('policy-carry-submit'))->assertCreated();

        $this->assertDatabaseHas('work_records', [
            'id' => $created->json('data.id'),
            'field_policy_key' => 'work_record.classified',
        ]);
    }

    public function test_idempotency_replay_is_stable_and_conflicting_semantics_are_rejected(): void
    {
        $token = $this->loginToken();
        $body = [
            'work_definition_code' => 'request',
            'title' => 'طلب ثابت',
            'description' => 'وصف ثابت',
        ];

        $first = $this->withToken($token)->postJson('/api/v1/work-records', $body, $this->writeHeaders())->assertCreated();
        $replay = $this->withToken($token)->postJson('/api/v1/work-records', $body, $this->writeHeaders())->assertCreated();
        $conflict = $this->withToken($token)->postJson('/api/v1/work-records', [
            ...$body,
            'title' => 'طلب مختلف',
        ], $this->writeHeaders());

        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $conflict->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/idempotency-conflict');
        $this->assertDatabaseCount('work_records', 1);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertDatabaseCount('work_record_idempotency_keys', 1);
        $this->assertDatabaseHas('work_record_idempotency_keys', [
            'principal_id' => '018f6f7d-0c00-7000-8000-000000000021',
            'facility_id' => '018f6f7d-0c00-7000-8000-000000000011',
            'operation' => 'createWorkRecord',
            'idempotency_key_hash' => hash('sha256', 'adapter-test-request'),
        ]);
        $this->assertDatabaseMissing('work_record_idempotency_keys', [
            'idempotency_key_hash' => 'adapter-test-request',
        ]);
    }

    public function test_replay_remains_database_backed_after_post_commit_cache_loss(): void
    {
        $token = $this->loginToken();
        $body = $this->validBody('طلب بعد Commit');
        $headers = $this->writeHeaders('database-backed-replay');

        $first = $this->withToken($token)->postJson('/api/v1/work-records', $body, $headers)->assertCreated();
        Cache::store('file')->flush();
        $replacementToken = $this->loginToken();
        $replay = $this->withToken($replacementToken)->postJson('/api/v1/work-records', $body, $headers)->assertCreated();

        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertDatabaseCount('work_records', 1);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertDatabaseCount('work_record_idempotency_keys', 1);
    }

    public function test_replay_reauthorizes_current_record_facts_and_conceals_revoked_access(): void
    {
        $token = $this->loginToken();
        $body = $this->validBody('طلب سحبت صلاحيته');
        $headers = $this->writeHeaders('revoked-replay');
        $recordId = $this->withToken($token)
            ->postJson('/api/v1/work-records', $body, $headers)
            ->assertCreated()
            ->json('data.id');

        DB::table('work_records')->where('id', $recordId)->update([
            'owner_facility_id' => '018f6f7d-0c00-7000-8000-000000000012',
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/work-records', $body, $headers);

        $response->assertNotFound()->assertExactJson([
            'type' => 'https://cluster.example/problems/work-record-unavailable',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => 'لا يمكنك فتح هذا الطلب أو لم يعد متاحاً.',
        ]);
        $this->assertStringNotContainsString((string) $recordId, $response->getContent());
        $this->assertDatabaseCount('work_records', 1);
        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_replay_authorizes_the_full_response_with_read_instead_of_submit(): void
    {
        $token = $this->loginToken();
        $body = $this->validBody('طلب يعاد بصلاحية القراءة');
        $headers = $this->writeHeaders('read-authorized-replay');

        $access = new class implements DecideAccess
        {
            /** @var list<string> */
            public array $capabilities = [];

            public bool $allowSubmit = true;

            /**
             * Test doubles persist nothing, so the read-side evaluation IS decide().
             */
            public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                return $this->decide($actor, $capability, $facts);
            }

            public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                $this->capabilities[] = $capability;

                return new AccessDecision(
                    decision: $capability === 'work_record.read' || ($capability === 'work_record.submit' && $this->allowSubmit)
                        ? 'allow'
                        : 'deny',
                    action: $capability,
                    resourceType: $facts->resourceType,
                    reasonCodes: ['focused_replay_test'],
                    policyVersion: 'focused-replay-test-v1',
                    factsVersion: $facts->factsVersion,
                    classification: $facts->classification,
                );
            }
        };
        $this->app->instance(DecideAccess::class, $access);

        $created = $this->withToken($token)
            ->postJson('/api/v1/work-records', $body, $headers)
            ->assertCreated();
        $access->capabilities = [];
        $access->allowSubmit = false;

        $replay = $this->withToken($token)
            ->postJson('/api/v1/work-records', $body, $headers)
            ->assertCreated();

        $this->assertSame($created->json('data.id'), $replay->json('data.id'));
        $this->assertSame(['work_record.read'], $access->capabilities);
        $this->assertDatabaseCount('work_records', 1);
        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_malformed_persisted_idempotency_state_fails_closed_without_a_second_write(): void
    {
        $token = $this->loginToken();
        $body = $this->validBody('طلب بحالة غير مكتملة');
        $key = 'malformed-idempotency-state';
        DB::table('work_record_idempotency_keys')->insert([
            'principal_id' => '018f6f7d-0c00-7000-8000-000000000021',
            'facility_id' => '018f6f7d-0c00-7000-8000-000000000011',
            'operation' => 'createWorkRecord',
            'idempotency_key_hash' => hash('sha256', $key),
            'request_hash' => hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)),
            'work_record_id' => '0197f0e0-0000-7000-8000-000000000999',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/work-records', $body, $this->writeHeaders($key))
            ->assertStatus(500)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/idempotency-state-unavailable');

        $this->assertDatabaseCount('work_records', 0);
        $this->assertDatabaseCount('outbox_events', 0);
        $this->assertDatabaseCount('work_record_idempotency_keys', 1);
    }

    public function test_list_uses_opaque_cursor_pagination_and_rejects_malformed_collection_state(): void
    {
        $token = $this->loginToken();
        foreach (range(1, 3) as $number) {
            $this->withToken($token)
                ->postJson(
                    '/api/v1/work-records',
                    $this->validBody("طلب {$number}"),
                    $this->writeHeaders("pagination-{$number}"),
                )
                ->assertCreated();
        }

        $first = $this->withToken($token)
            ->getJson('/api/v1/work-records?limit=2&classification=internal', $this->headers())
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonStructure(['items', 'next_cursor'])
            ->assertHeader('Link');
        $cursor = $first->json('next_cursor');
        $this->assertIsString($cursor);

        $this->withToken($token)
            ->getJson('/api/v1/work-records?limit=2&classification=internal&cursor='.rawurlencode($cursor), $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('next_cursor', null)
            ->assertHeaderMissing('Link');

        $malformed = $this->withToken($token)
            ->getJson('/api/v1/work-records?cursor=not-a-valid-cursor', $this->headers())
            ->assertBadRequest()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-pagination');

        $classificationMismatch = $this->withToken($token)
            ->getJson('/api/v1/work-records?limit=2&classification=confidential&cursor='.rawurlencode($cursor), $this->headers())
            ->assertBadRequest();
        $limitMismatch = $this->withToken($token)
            ->getJson('/api/v1/work-records?limit=3&classification=internal&cursor='.rawurlencode($cursor), $this->headers())
            ->assertBadRequest();
        $sameFacilityToken = $this->app->make(ResolveDevelopmentFixturePrincipal::class)->issue([
            'user_id' => '018f6f7d-0c00-7000-8000-000000000023',
            'facility_id' => '018f6f7d-0c00-7000-8000-000000000011',
        ])['access_token'];
        $otherPrincipal = $this->withToken($sameFacilityToken)
            ->getJson('/api/v1/work-records?limit=2&classification=internal&cursor='.rawurlencode($cursor), $this->headers())
            ->assertBadRequest();
        $otherFacility = $this->withToken($this->loginToken('fixture-account-b', 'fixture-password-b'))
            ->getJson('/api/v1/work-records?limit=2&classification=internal&cursor='.rawurlencode($cursor), $this->headers())
            ->assertBadRequest();

        foreach ([$classificationMismatch, $limitMismatch, $otherPrincipal, $otherFacility] as $mismatch) {
            $this->assertSame($malformed->getContent(), $mismatch->getContent());
            foreach (['internal', 'confidential', 'principal', 'facility', 'scope', 'cursor'] as $metadata) {
                $this->assertStringNotContainsString($metadata, $mismatch->getContent());
            }
        }
    }

    private function requireHttpSlices(): void
    {
        if (! class_exists(SubmitWorkRecordController::class)
            || ! class_exists(GetAuthorizedWorkRecordController::class)
            || ! class_exists(ListAuthorizedWorkRecordsController::class)) {
            $this->markTestSkipped('The deliberate missing-slices test owns the RED marker.');
        }
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
    private function writeHeaders(string $idempotencyKey = 'adapter-test-request'): array
    {
        return [
            ...$this->headers(),
            'Idempotency-Key' => $idempotencyKey,
        ];
    }

    /** @return array{work_definition_code: string, title: string, description: string} */
    private function validBody(string $title): array
    {
        return [
            'work_definition_code' => 'request',
            'title' => $title,
            'description' => 'وصف صالح',
        ];
    }
}
