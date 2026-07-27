<?php

namespace Modules\Organization\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupervisoryRelationshipHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '0197f0e0-0000-7000-8000-000000000a01';

    private const BOOTSTRAP_ADMIN_USER_ID = '018f6f7d-0c00-7000-8000-000000000021';

    private ?string $singletonClusterId = null;

    public function test_post_creates_persists_and_replays_with_idempotent_response(): void
    {
        $token = $this->loginToken();
        [$sourceUnitId, $targetUnitId] = $this->createUnits($token, 'CREATE');

        $body = $this->relationshipBody($sourceUnitId, $targetUnitId);
        $headers = $this->writeHeaders('supervisory-create');

        $created = $this->withToken($token)
            ->postJson('/api/v1/organization/supervisory-relationships', $body, $headers)
            ->assertCreated()
            ->assertHeader('ETag', '"1"')
            ->assertHeader('X-Correlation-ID', self::CORRELATION_ID)
            ->assertJsonPath('data.source_unit_id', $sourceUnitId)
            ->assertJsonPath('data.target_unit_id', $targetUnitId)
            ->assertJsonPath('data.source_organization_unit_id', $sourceUnitId)
            ->assertJsonPath('data.target_organization_unit_id', $targetUnitId)
            ->assertJsonPath('data.relationship_type', 'direct')
            ->assertJsonPath('data.lock_version', 1);
        $relationshipId = $created->json('data.id');
        $this->assertIsString($relationshipId);

        $createdCapabilityCodes = collect($created->json('data.capabilities'))
            ->pluck('capability_code')
            ->all();
        $this->assertSame(['organization.reports.read'], $createdCapabilityCodes);

        $this->assertDatabaseHas('supervisory_relationships', [
            'id' => $relationshipId,
            'source_organization_unit_id' => $sourceUnitId,
            'target_organization_unit_id' => $targetUnitId,
            'relationship_type' => 'direct',
        ]);
        $this->assertDatabaseHas('relationship_capabilities', [
            'supervisory_relationship_id' => $relationshipId,
            'module_code' => 'organization',
            'capability_code' => 'organization.reports.read',
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'com.cluster.organization.supervisoryrelationshipcreated.v1',
            'aggregate_id' => $relationshipId,
        ]);
        $outboxEvent = DB::table('outbox_events')
            ->where('event_type', 'com.cluster.organization.supervisoryrelationshipcreated.v1')
            ->where('aggregate_id', $relationshipId)
            ->first(['cloud_event']);
        $this->assertNotNull($outboxEvent);
        $cloudEvent = json_decode((string) $outboxEvent->cloud_event, true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('com.cluster.organization.supervisoryrelationshipcreated.v1', $cloudEvent['type']);
        $this->assertSame('/organization/supervisory-relationships/'.$relationshipId, $cloudEvent['subject']);
        $this->assertSame($sourceUnitId, $cloudEvent['data']['supervisory_relationship']['source_unit_id']);
        $this->assertSame($targetUnitId, $cloudEvent['data']['supervisory_relationship']['target_unit_id']);
        $this->assertSame('direct', $cloudEvent['data']['supervisory_relationship']['relationship_type']);

        $replayed = $this->withToken($token)
            ->postJson('/api/v1/organization/supervisory-relationships', $body, $headers)
            ->assertCreated()
            ->assertHeader('ETag', '"1"');
        $this->assertSame($relationshipId, $replayed->json('data.id'));
        $this->assertDatabaseCount('supervisory_relationships', 1);
        $this->assertSame(1, DB::table('outbox_events')
            ->where('event_type', 'com.cluster.organization.supervisoryrelationshipcreated.v1')
            ->count());
        $this->assertSame(1, DB::table('authorization_idempotency_keys')
            ->where('operation', 'create-supervisory-relationship')
            ->where('resource_id', $relationshipId)
            ->where('response_status', 201)
            ->count());
        $this->assertSame(self::BOOTSTRAP_ADMIN_USER_ID, (string) DB::table('authorization_idempotency_keys')
            ->where('operation', 'create-supervisory-relationship')
            ->where('resource_id', $relationshipId)
            ->value('principal_id'));
    }

    public function test_post_rejects_same_key_for_a_different_payload_as_a_conflict(): void
    {
        $token = $this->loginToken();
        [$sourceUnitId, $targetUnitId] = $this->createUnits($token, 'CONFLICT');

        $body = $this->relationshipBody($sourceUnitId, $targetUnitId);

        $this->withToken($token)
            ->postJson('/api/v1/organization/supervisory-relationships', $body, $this->writeHeaders('supervisory-conflict'))
            ->assertCreated();

        $this->withToken($token)
            ->postJson(
                '/api/v1/organization/supervisory-relationships',
                [...$body, 'relationship_type' => 'functional'],
                $this->writeHeaders('supervisory-conflict'),
            )
            ->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/idempotency-conflict');

        $this->assertDatabaseCount('supervisory_relationships', 1);
    }

    public function test_post_rejects_invalid_correlation_id_on_both_methods(): void
    {
        $token = $this->loginToken();
        [$sourceUnitId, $targetUnitId] = $this->createUnits($token, 'CORRELATION');
        $body = $this->relationshipBody($sourceUnitId, $targetUnitId);

        $missing = $this->withToken($token)
            ->postJson('/api/v1/organization/supervisory-relationships', $body, ['Idempotency-Key' => 'supervisory-no-correlation'])
            ->assertBadRequest();
        $this->assertSame(
            'https://cluster.example/problems/invalid-correlation-id',
            $missing->json('type'),
        );

        $malformed = $this->withToken($token)
            ->getJson(
                '/api/v1/organization/supervisory-relationships',
                ['X-Correlation-ID' => 'not-a-uuidv7'],
            )
            ->assertBadRequest();
        $this->assertSame(
            'https://cluster.example/problems/invalid-correlation-id',
            $malformed->json('type'),
        );
    }

    public function test_get_returns_401_and_403_under_the_expected_access_boundaries(): void
    {
        $token = $this->loginToken();
        [$sourceUnitId, $targetUnitId] = $this->createUnits($token, 'ACCESS');
        $this->withToken($token)
            ->postJson(
                '/api/v1/organization/supervisory-relationships',
                $this->relationshipBody($sourceUnitId, $targetUnitId),
                $this->writeHeaders('supervisory-access-bootstrap'),
            )
            ->assertCreated();

        $this->withHeaders(['Authorization' => ''])
            ->getJson('/api/v1/organization/supervisory-relationships', $this->headers())
            ->assertUnauthorized()
            ->assertJsonPath('type', 'https://cluster.example/problems/authentication-required');

        $other = $this->loginToken('fixture-account-b', 'fixture-password-b');
        $this->withToken($other)
            ->getJson('/api/v1/organization/supervisory-relationships', $this->headers())
            ->assertForbidden()
            ->assertJsonPath('type', 'https://cluster.example/problems/access-denied');
        $this->withToken($other)
            ->postJson(
                '/api/v1/organization/supervisory-relationships',
                $this->relationshipBody($sourceUnitId, $targetUnitId),
                $this->writeHeaders('supervisory-access-denied'),
            )
            ->assertForbidden()
            ->assertJsonPath('type', 'https://cluster.example/problems/access-denied');
    }

    public function test_post_rejects_payload_violations_with_the_expected_problem_types(): void
    {
        $token = $this->loginToken();
        [$sourceUnitId, $targetUnitId] = $this->createUnits($token, 'VALIDATION');

        $valid = $this->relationshipBody($sourceUnitId, $targetUnitId);

        $missingBody = $valid;
        unset(
            $missingBody['relationship_type'],
            $missingBody['start_at'],
            $missingBody['end_at'],
            $missingBody['capability_codes'],
        );
        $this->withToken($token)
            ->postJson(
                '/api/v1/organization/supervisory-relationships',
                $missingBody,
                $this->writeHeaders('supervisory-missing-fields'),
            )
            ->assertStatus(422)
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-supervisory-relationship');

        $this->withToken($token)
            ->postJson(
                '/api/v1/organization/supervisory-relationships',
                [...$valid, 'unexpected_key' => 'unsupported'],
                $this->writeHeaders('supervisory-unknown-key'),
            )
            ->assertStatus(422)
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-supervisory-relationship');

        $this->withToken($token)
            ->postJson(
                '/api/v1/organization/supervisory-relationships',
                [
                    ...$valid,
                    'source_unit_id' => 'not-a-uuid-at-all',
                ],
                $this->writeHeaders('supervisory-bad-uuid'),
            )
            ->assertStatus(422)
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-supervisory-relationship');

        $this->withToken($token)
            ->postJson(
                '/api/v1/organization/supervisory-relationships',
                [
                    ...$valid,
                    'start_at' => '2026-01-02 03:04:05.000Z',
                    'end_at' => '2026-01-03 03:04:05.000Z',
                ],
                $this->writeHeaders('supervisory-bad-window'),
            )
            ->assertStatus(422)
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-supervisory-relationship');

        $this->withToken($token)
            ->postJson(
                '/api/v1/organization/supervisory-relationships',
                [
                    ...$valid,
                    'start_at' => '2026-01-03T03:04:05.000Z',
                    'end_at' => '2026-01-02T03:04:05.000Z',
                ],
                $this->writeHeaders('supervisory-inverted-window'),
            )
            ->assertStatus(422)
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-supervisory-relationship');

        $this->withToken($token)
            ->postJson(
                '/api/v1/organization/supervisory-relationships',
                [
                    ...$valid,
                    'source_unit_id' => '018f6f7d-0c00-7000-8000-000000000499',
                ],
                $this->writeHeaders('supervisory-missing-unit'),
            )
            ->assertNotFound()
            ->assertJsonPath('type', 'https://cluster.example/problems/organization-unit-not-found');

        $this->assertDatabaseCount('supervisory_relationships', 0);
    }

    public function test_post_rejects_missing_idempotency_key_and_same_unit_pair_as_conflict(): void
    {
        $token = $this->loginToken();
        [$sourceUnitId, $targetUnitId] = $this->createUnits($token, 'IDEMPOTENCY');

        $this->withToken($token)
            ->postJson(
                '/api/v1/organization/supervisory-relationships',
                $this->relationshipBody($sourceUnitId, $targetUnitId),
                $this->headers(),
            )
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-idempotency-key');

        $this->withToken($token)
            ->postJson(
                '/api/v1/organization/supervisory-relationships',
                [
                    ...$this->relationshipBody($sourceUnitId, $targetUnitId),
                    'target_unit_id' => $sourceUnitId,
                ],
                $this->writeHeaders('supervisory-self-target'),
            )
            ->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/supervisory-relationship-conflict');
    }

    public function test_get_lists_created_rows_and_paginates_with_a_cursor(): void
    {
        $token = $this->loginToken();
        $firstPair = $this->createUnits($token, 'PAGE-FIRST');
        $secondPair = $this->createUnits($token, 'PAGE-SECOND');
        $thirdPair = $this->createUnits($token, 'PAGE-THIRD');

        $firstBody = $this->relationshipBody($firstPair[0], $firstPair[1]);
        $secondBody = $this->relationshipBody($secondPair[0], $secondPair[1]);
        $thirdBody = $this->relationshipBody($thirdPair[0], $thirdPair[1]);

        $firstId = (string) $this->withToken($token)
            ->postJson('/api/v1/organization/supervisory-relationships', $firstBody, $this->writeHeaders('supervisory-page-first'))
            ->assertCreated()
            ->json('data.id');
        $secondId = (string) $this->withToken($token)
            ->postJson('/api/v1/organization/supervisory-relationships', $secondBody, $this->writeHeaders('supervisory-page-second'))
            ->assertCreated()
            ->json('data.id');
        $thirdId = (string) $this->withToken($token)
            ->postJson('/api/v1/organization/supervisory-relationships', $thirdBody, $this->writeHeaders('supervisory-page-third'))
            ->assertCreated()
            ->json('data.id');

        $listed = $this->withToken($token)
            ->getJson('/api/v1/organization/supervisory-relationships?limit=100', $this->headers())
            ->assertOk()
            ->assertHeader('X-Correlation-ID', self::CORRELATION_ID)
            ->assertJsonCount(3, 'items');
        $this->assertNull($listed->json('next_cursor'));
        $listedIds = collect($listed->json('items'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$firstId, $secondId, $thirdId], $listedIds);

        $page = $this->withToken($token)
            ->getJson('/api/v1/organization/supervisory-relationships?limit=1', $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertHeader('Link');
        $cursor = $page->json('next_cursor');
        $this->assertIsString($cursor);
        $firstPageIds = collect($page->json('items'))->pluck('id')->all();
        $this->assertSame(1, count(array_unique($firstPageIds)));
        $this->assertSame($firstId, $firstPageIds[0]);

        $secondPage = $this->withToken($token)
            ->getJson(
                '/api/v1/organization/supervisory-relationships?limit=1&cursor='.rawurlencode($cursor),
                $this->headers(),
            )
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('next_cursor', null);
        $secondPageIds = collect($secondPage->json('items'))->pluck('id')->all();
        $this->assertSame($thirdId, $secondPageIds[0]);

        $this->assertNull($secondPage->json('next_cursor'));
    }

    public function test_get_rejects_invalid_pagination_parameters(): void
    {
        $token = $this->loginToken();

        $this->withToken($token)
            ->getJson(
                '/api/v1/organization/supervisory-relationships?cursor='.rawurlencode('@@@not-a-valid-cursor@@@'),
                $this->headers(),
            )
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-pagination');

        $this->withToken($token)
            ->getJson(
                '/api/v1/organization/supervisory-relationships?cursor='.rawurlencode('***malformed***'),
                $this->headers(),
            )
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-pagination');

        $this->withToken($token)
            ->getJson(
                '/api/v1/organization/supervisory-relationships?limit=999',
                $this->headers(),
            )
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-pagination');

        $this->withToken($token)
            ->getJson(
                '/api/v1/organization/supervisory-relationships?extra=1',
                $this->headers(),
            )
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-pagination');
    }

    /**
     * @return array{string, string}
     */
    private function createUnits(string $token, string $suffix = ''): array
    {
        $clusterId = $this->singletonClusterId();
        if ($clusterId === null) {
            $clusterId = $this->withToken($token)
                ->postJson(
                    '/api/v1/organization/cluster',
                    ['code' => 'THC'.Str::upper(Str::random(4)), 'name' => 'تجمع العلاقات الإشرافية'],
                    $this->writeHeaders('supervisory-cluster'),
                )
                ->assertCreated()
                ->json('data.id');
            $this->assertIsString($clusterId);
            $this->singletonClusterId = $clusterId;
        }

        $sourceUnitId = $this->withToken($token)
            ->postJson(
                '/api/v1/organization/units',
                [
                    'cluster_id' => $clusterId,
                    'type_code' => 'sector',
                    'code' => 'SRC-'.$suffix.'-'.Str::upper(Str::random(4)),
                    'name' => 'القطاع المصدر',
                ],
                $this->writeHeaders('supervisory-source-'.$suffix),
            )
            ->assertCreated()
            ->json('data.id');
        $this->assertIsString($sourceUnitId);

        $targetUnitId = $this->withToken($token)
            ->postJson(
                '/api/v1/organization/units',
                [
                    'cluster_id' => $clusterId,
                    'type_code' => 'department',
                    'code' => 'TGT-'.$suffix.'-'.Str::upper(Str::random(4)),
                    'name' => 'الإدارة الهدف',
                ],
                $this->writeHeaders('supervisory-target-'.$suffix),
            )
            ->assertCreated()
            ->json('data.id');
        $this->assertIsString($targetUnitId);

        return [$sourceUnitId, $targetUnitId];
    }

    private function singletonClusterId(): ?string
    {
        if ($this->singletonClusterId !== null) {
            return $this->singletonClusterId;
        }

        $id = (string) DB::table('clusters')->where('singleton_key', 1)->value('id');

        return $id === '' ? null : $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function relationshipBody(string $sourceUnitId, string $targetUnitId): array
    {
        return [
            'source_unit_id' => $sourceUnitId,
            'target_unit_id' => $targetUnitId,
            'relationship_type' => 'direct',
            'start_at' => now('UTC')->subHour()->format('Y-m-d\TH:i:s.v\Z'),
            'end_at' => now('UTC')->addDay()->format('Y-m-d\TH:i:s.v\Z'),
            'capability_codes' => ['organization.reports.read'],
        ];
    }

    private function loginToken(string $username = 'fixture-account-a', string $password = 'fixture-password-a'): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'username' => $username,
            'password' => $password,
        ], $this->headers())->assertOk()->json('data.access_token');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['X-Correlation-ID' => self::CORRELATION_ID];
    }

    /**
     * @return array<string, string>
     */
    private function writeHeaders(string $key): array
    {
        return [...$this->headers(), 'Idempotency-Key' => $key];
    }
}
