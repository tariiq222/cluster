<?php

namespace Modules\Organization\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proves the deliberately-weak If-None-Match cache contract of GET
 * /api/v1/organization/temporary-assignments/{id} against the real
 * database, real gateway, and real TemporaryAssignmentApi validator
 * (W/"temporary-assignment-{id}-v{lockVersion}-{state}"). The fake
 * gateway coverage in tests/Feature/.../OrganizationTemporaryAssignmentHttpAdapterTest.php
 * only asserts the wire-level branch; the handler's effective-state
 * derivation from the clock is only observable here.
 */
final class TemporaryAssignmentCacheHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '0197f0e0-0000-7000-8000-000000000e01';

    private const FIXED_NOW = '2026-07-18T10:00:00.000Z';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(self::FIXED_NOW);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_get_returns_304_when_if_none_match_matches_the_weak_validator_of_a_pending_assignment(): void
    {
        $token = $this->loginToken();
        [$personId, $unitId] = $this->seedReferences($token);
        $assignmentId = $this->createAssignment(
            $token,
            $personId,
            $unitId,
            'cache-304-pending',
            '+1 hour',
            '+2 hours',
        );

        $first = $this->withToken($token)
            ->getJson("/api/v1/organization/temporary-assignments/{$assignmentId}", $this->headers())
            ->assertOk()
            ->assertHeader('ETag', 'W/"temporary-assignment-'.$assignmentId.'-v1-pending"')
            ->assertHeader('X-Resource-Version', '"1"')
            ->assertJsonPath('data.id', $assignmentId)
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonMissingPath('data.representation_etag');

        $validator = $first->headers->get('ETag');
        $this->assertIsString($validator);

        $notModified = $this->withToken($token)
            ->getJson(
                "/api/v1/organization/temporary-assignments/{$assignmentId}",
                [...$this->headers(), 'If-None-Match' => $validator],
            );

        $notModified->assertStatus(304);
        $this->assertSame('', $notModified->getContent());
        $this->assertSame($validator, $notModified->headers->get('ETag'));
        $this->assertSame('"1"', $notModified->headers->get('X-Resource-Version'));
        $this->assertSame(self::CORRELATION_ID, $notModified->headers->get('X-Correlation-ID'));

        $this->assertDatabaseCount('temporary_assignments', 1);
    }

    public function test_get_returns_200_with_full_body_when_if_none_match_does_not_match(): void
    {
        $token = $this->loginToken();
        [$personId, $unitId] = $this->seedReferences($token);
        $assignmentId = $this->createAssignment(
            $token,
            $personId,
            $unitId,
            'cache-200-stale',
            '+1 hour',
            '+2 hours',
        );

        $mismatched = 'W/"temporary-assignment-'.$assignmentId.'-v999-expired"';

        $response = $this->withToken($token)
            ->getJson(
                "/api/v1/organization/temporary-assignments/{$assignmentId}",
                [...$this->headers(), 'If-None-Match' => $mismatched],
            )
            ->assertOk()
            ->assertHeader('ETag', 'W/"temporary-assignment-'.$assignmentId.'-v1-pending"')
            ->assertHeader('X-Resource-Version', '"1"')
            ->assertJsonPath('data.id', $assignmentId)
            ->assertJsonPath('data.person_id', $personId)
            ->assertJsonPath('data.organization_unit_id', $unitId)
            ->assertJsonPath('data.status', 'scheduled');

        $body = $response->json();
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $this->assertSame(['records.approve', 'records.read'], $body['data']['capability_codes']);
        $this->assertSame(1, $body['data']['lock_version']);
        $this->assertArrayNotHasKey('representation_etag', $body['data']);
    }

    public function test_get_advances_the_weak_validator_when_the_effective_state_transitions_via_the_clock(): void
    {
        $token = $this->loginToken();
        [$personId, $unitId] = $this->seedReferences($token);

        // Create with a window fully in the future relative to FIXED_NOW so the create
        // handler accepts it (start_at must not be backdated). Then walk the clock
        // pending -> active (past start_at) -> expired (past end_at) and assert the
        // weak validator advances on every transition without mutating the row.
        $now = CarbonImmutable::parse(self::FIXED_NOW);
        $start = $now->addMinutes(10);
        $end = $now->addMinutes(30);
        $assignmentId = $this->createAssignment(
            $token,
            $personId,
            $unitId,
            'cache-clock-advance',
            $start->format('Y-m-d\TH:i:s.v\Z'),
            $end->format('Y-m-d\TH:i:s.v\Z'),
        );

        $pendingValidator = 'W/"temporary-assignment-'.$assignmentId.'-v1-pending"';
        $activeValidator = 'W/"temporary-assignment-'.$assignmentId.'-v1-active"';
        $expiredValidator = 'W/"temporary-assignment-'.$assignmentId.'-v1-expired"';

        // Before start_at: state is 'pending'. The 304 branch accepts the matching validator.
        $this->withToken($token)
            ->getJson("/api/v1/organization/temporary-assignments/{$assignmentId}", $this->headers())
            ->assertOk()
            ->assertHeader('ETag', $pendingValidator)
            ->assertJsonPath('data.status', 'scheduled');

        $this->withToken($token)
            ->getJson(
                "/api/v1/organization/temporary-assignments/{$assignmentId}",
                [...$this->headers(), 'If-None-Match' => $pendingValidator],
            )
            ->assertStatus(304);

        // Persisted row.state at create time was 'pending' (start_at was in the future).
        $this->assertSame(
            'pending',
            (string) DB::table('temporary_assignments')->where('id', $assignmentId)->value('state'),
        );

        // Walk the clock to just past start_at. effectiveState() returns 'active' but
        // the row.state on disk stays 'pending' — only the weak validator must move.
        CarbonImmutable::setTestNow($start->addSecond()->format('Y-m-d\TH:i:s.v\Z'));

        $this->withToken($token)
            ->getJson(
                "/api/v1/organization/temporary-assignments/{$assignmentId}",
                [...$this->headers(), 'If-None-Match' => $pendingValidator],
            )
            ->assertOk()
            ->assertHeader('ETag', $activeValidator)
            ->assertJsonPath('data.status', 'active');

        $this->withToken($token)
            ->getJson(
                "/api/v1/organization/temporary-assignments/{$assignmentId}",
                [...$this->headers(), 'If-None-Match' => $activeValidator],
            )
            ->assertStatus(304);

        // The row on disk still says 'pending' — only the derived validator moved.
        $this->assertSame(
            'pending',
            (string) DB::table('temporary_assignments')->where('id', $assignmentId)->value('state'),
        );

        // Walk the clock past end_at. effectiveState() now returns 'expired'.
        CarbonImmutable::setTestNow($end->addSecond()->format('Y-m-d\TH:i:s.v\Z'));

        $this->withToken($token)
            ->getJson(
                "/api/v1/organization/temporary-assignments/{$assignmentId}",
                [...$this->headers(), 'If-None-Match' => $activeValidator],
            )
            ->assertOk()
            ->assertHeader('ETag', $expiredValidator)
            ->assertJsonPath('data.status', 'expired');

        $this->withToken($token)
            ->getJson(
                "/api/v1/organization/temporary-assignments/{$assignmentId}",
                [...$this->headers(), 'If-None-Match' => $expiredValidator],
            )
            ->assertStatus(304);

        // Disk state still 'pending' — the expiration command is what would persist 'expired'.
        $this->assertSame(
            'pending',
            (string) DB::table('temporary_assignments')->where('id', $assignmentId)->value('state'),
        );
    }

    public function test_get_returns_404_for_unknown_id_and_enforces_access_boundaries(): void
    {
        $token = $this->loginToken();
        [$personId, $unitId] = $this->seedReferences($token);
        $assignmentId = $this->createAssignment(
            $token,
            $personId,
            $unitId,
            'cache-boundary',
            '+1 hour',
            '+2 hours',
        );

        $this->withHeaders(['Authorization' => ''])
            ->getJson("/api/v1/organization/temporary-assignments/{$assignmentId}", $this->headers())
            ->assertUnauthorized()
            ->assertJsonPath('type', 'https://cluster.example/problems/authentication-required');

        $unknown = '0197f0e0-0000-7000-8000-000000000e99';
        $this->withToken($token)
            ->getJson("/api/v1/organization/temporary-assignments/{$unknown}", $this->headers())
            ->assertNotFound()
            ->assertJsonPath('type', 'https://cluster.example/problems/temporary-assignment-not-found');

        // The 304 path with an unknown id must 404 because the row lookup runs
        // before cache matching and never reaches the validator comparison.
        $this->withToken($token)
            ->getJson(
                "/api/v1/organization/temporary-assignments/{$unknown}",
                [...$this->headers(), 'If-None-Match' => 'W/"temporary-assignment-'.$unknown.'-v1-active"'],
            )
            ->assertNotFound();

        $this->withToken($token)
            ->getJson('/api/v1/organization/temporary-assignments/not-a-uuid-v7', $this->headers())
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-temporary-assignment-reference');
    }

    public function test_get_honors_a_multi_value_if_none_match_header_containing_the_current_validator(): void
    {
        $token = $this->loginToken();
        [$personId, $unitId] = $this->seedReferences($token);
        $assignmentId = $this->createAssignment(
            $token,
            $personId,
            $unitId,
            'cache-multivalue',
            '+1 hour',
            '+2 hours',
        );

        $current = 'W/"temporary-assignment-'.$assignmentId.'-v1-pending"';
        $stale = 'W/"temporary-assignment-'.$assignmentId.'-v0-active"';

        $this->withToken($token)
            ->getJson(
                "/api/v1/organization/temporary-assignments/{$assignmentId}",
                [...$this->headers(), 'If-None-Match' => $stale.', '.$current],
            )
            ->assertStatus(304)
            ->assertHeader('ETag', $current);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function seedReferences(string $token): array
    {
        $clusterId = $this->withToken($token)
            ->postJson(
                '/api/v1/organization/cluster',
                ['code' => 'THC'.Str::upper(Str::random(4)), 'name' => 'تجمع اختبارات الكاش'],
                [...$this->headers(), 'Idempotency-Key' => 'cache-cluster'],
            )
            ->assertCreated()
            ->json('data.id');
        $this->assertIsString($clusterId);

        $unitId = $this->withToken($token)
            ->postJson(
                '/api/v1/organization/units',
                [
                    'cluster_id' => $clusterId,
                    'type_code' => 'department',
                    'code' => 'CACHE-'.Str::upper(Str::random(4)),
                    'name' => 'إدارة اختبارات الكاش',
                ],
                [...$this->headers(), 'Idempotency-Key' => 'cache-unit'],
            )
            ->assertCreated()
            ->json('data.id');
        $this->assertIsString($unitId);

        $personId = $this->withToken($token)
            ->postJson(
                '/api/v1/organization/people',
                [
                    'employee_number' => 'EMP-CACHE-'.Str::upper(Str::random(6)),
                    'display_name_ar' => 'موظف اختبارات الكاش',
                    'status' => 'active',
                ],
                [...$this->headers(), 'Idempotency-Key' => 'cache-person'],
            )
            ->assertCreated()
            ->json('data.id');
        $this->assertIsString($personId);

        return [$personId, $unitId];
    }

    /**
     * @param  string|CarbonImmutable  $startAt
     * @param  string|CarbonImmutable  $endAt
     */
    private function createAssignment(
        string $token,
        string $personId,
        string $unitId,
        string $idempotencyKey,
        $startAt,
        $endAt,
    ): string {
        $now = CarbonImmutable::now('UTC');
        $body = [
            'person_id' => $personId,
            'organization_unit_id' => $unitId,
            'capability_codes' => ['records.read', 'records.approve'],
            'start_at' => $this->formatAt($startAt, $now),
            'end_at' => $this->formatAt($endAt, $now),
            'reason' => 'تغطية اختبارات الكاش',
        ];

        $id = $this->withToken($token)
            ->postJson(
                '/api/v1/organization/temporary-assignments',
                $body,
                [...$this->headers(), 'Idempotency-Key' => $idempotencyKey],
            )
            ->assertCreated()
            ->json('data.id');
        $this->assertIsString($id);
        $this->assertNotSame('', $id);

        return $id;
    }

    /**
     * @param  string|CarbonImmutable  $value
     */
    private function formatAt($value, CarbonImmutable $now): string
    {
        if ($value instanceof CarbonImmutable) {
            return $value->format('Y-m-d\TH:i:s.v\Z');
        }

        if (preg_match('/\A[+-]/', $value) === 1) {
            return $now->modify($value)->format('Y-m-d\TH:i:s.v\Z');
        }

        return $value;
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
}
