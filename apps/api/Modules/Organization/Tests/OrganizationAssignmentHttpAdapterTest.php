<?php

namespace Modules\Organization\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Organization\Features\Assignment\Handler\AssignmentHandler;
use Tests\TestCase;

class OrganizationAssignmentHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000501';

    public function test_assignment_is_created_replayed_and_listed_with_minimized_event_data(): void
    {
        [$token, $personId, $positionId] = $this->assignmentReferences();
        $body = $this->assignmentBody($personId, $positionId);

        $created = $this->withToken($token)
            ->postJson('/api/v1/organization/assignments', $body, $this->writeHeaders('assignment-create'))
            ->assertCreated()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.person_id', $personId)
            ->assertJsonPath('data.position_id', $positionId)
            ->assertJsonPath('data.is_primary', true)
            ->assertJsonPath('data.status', 'active');
        $assignmentId = $created->json('data.id');
        $this->assertIsString($assignmentId);

        $replayed = $this->withToken($token)
            ->postJson('/api/v1/organization/assignments', $body, $this->writeHeaders('assignment-create'))
            ->assertCreated();
        $this->assertSame($assignmentId, $replayed->json('data.id'));
        $this->withToken($token)
            ->postJson('/api/v1/organization/assignments', [...$body, 'is_primary' => false], $this->writeHeaders('assignment-create'))
            ->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/idempotency-conflict');

        $this->withToken($token)
            ->getJson('/api/v1/organization/assignments?person_id='.$personId, $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $assignmentId);

        $event = json_decode((string) DB::table('outbox_events')
            ->where('aggregate_id', $assignmentId)
            ->where('event_type', 'com.cluster.organization.assignmentstarted.v1')
            ->value('cloud_event'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($assignmentId, $event['data']['assignment']['id']);
        $this->assertSame(self::CORRELATION_ID, $event['correlationid']);
        $this->assertArrayNotHasKey('display_name_ar', $event['data']['assignment']);
        $this->assertArrayNotHasKey('employee_number', $event['data']['assignment']);
        $this->assertDatabaseCount('assignments', 1);
    }

    public function test_assignment_rejects_missing_references_invalid_windows_and_overlaps(): void
    {
        [$token, $personId, $positionId] = $this->assignmentReferences();
        $body = $this->assignmentBody($personId, $positionId);
        $this->withToken($token)
            ->postJson('/api/v1/organization/assignments', $body, $this->writeHeaders('assignment-first'))
            ->assertCreated();

        $this->withToken($token)->postJson('/api/v1/organization/assignments', [
            ...$body,
            'position_id' => '018f6f7d-0c00-7000-8000-000000000599',
        ], $this->writeHeaders('assignment-missing-position'))->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/organization/assignments', [
            ...$body,
            'start_at' => now('UTC')->addDay()->format('Y-m-d\TH:i:s.v\Z'),
            'end_at' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
        ], $this->writeHeaders('assignment-invalid-window'))->assertBadRequest();
        $this->withToken($token)->postJson('/api/v1/organization/assignments', [
            ...$body,
            'start_at' => now('UTC')->subDays(2)->format('Y-m-d\TH:i:s.v\Z'),
            'end_at' => now('UTC')->subDay()->format('Y-m-d\TH:i:s.v\Z'),
        ], $this->writeHeaders('assignment-already-ended'))->assertBadRequest();
        $this->withToken($token)->postJson('/api/v1/organization/assignments', [
            ...$body,
            'end_at' => null,
        ], $this->writeHeaders('assignment-null-end'))->assertBadRequest();

        $secondPosition = $this->createPosition($token, $this->unitId($positionId), 'SECOND', 'منصب ثان');
        $this->withToken($token)->postJson('/api/v1/organization/assignments', [
            ...$body,
            'position_id' => $secondPosition,
        ], $this->writeHeaders('assignment-person-overlap'))
            ->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/primary-assignment-overlap');

        $secondPerson = $this->createPerson($token, 'EMP-ASSIGN-002', 'assignment-person-2');
        $this->withToken($token)->postJson('/api/v1/organization/assignments', [
            ...$body,
            'person_id' => $secondPerson,
        ], $this->writeHeaders('assignment-position-overlap'))
            ->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/position-assignment-overlap');
        $this->assertDatabaseCount('assignments', 1);
    }

    public function test_assignment_end_is_idempotent_versioned_and_terminal(): void
    {
        [$token, $personId, $positionId] = $this->assignmentReferences();
        $assignmentId = (string) $this->withToken($token)
            ->postJson('/api/v1/organization/assignments', $this->assignmentBody($personId, $positionId), $this->writeHeaders('assignment-end-create'))
            ->assertCreated()->json('data.id');
        $endBody = [
            'end_at' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'reason' => 'انتهاء التكليف',
        ];

        $this->withToken($token)
            ->postJson("/api/v1/organization/assignments/{$assignmentId}/end", $endBody, $this->writeHeaders('assignment-end-missing-etag'))
            ->assertBadRequest();
        $this->withToken($token)
            ->postJson("/api/v1/organization/assignments/{$assignmentId}/end", $endBody, $this->actionHeaders('"2"', 'assignment-end-stale'))
            ->assertStatus(412);
        $ended = $this->withToken($token)
            ->postJson("/api/v1/organization/assignments/{$assignmentId}/end", $endBody, $this->actionHeaders('"1"', 'assignment-end'))
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.status', 'ended')
            ->assertJsonPath('data.end_reason', 'انتهاء التكليف');
        $this->assertSame($assignmentId, $ended->json('data.id'));
        $this->withToken($token)
            ->postJson("/api/v1/organization/assignments/{$assignmentId}/end", $endBody, $this->actionHeaders('"1"', 'assignment-end'))
            ->assertOk()
            ->assertHeader('ETag', '"2"');
        $this->withToken($token)
            ->postJson("/api/v1/organization/assignments/{$assignmentId}/end", $endBody, $this->actionHeaders('"2"', 'assignment-end-again'))
            ->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/assignment-already-ended');
        $this->assertDatabaseHas('assignments', ['id' => $assignmentId, 'lock_version' => 2, 'end_reason' => 'انتهاء التكليف']);
        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $assignmentId,
            'event_type' => 'com.cluster.organization.assignmentended.v1',
        ]);
    }

    public function test_pending_assignment_can_be_cancelled_with_a_future_end_date(): void
    {
        [$token, $personId, $positionId] = $this->assignmentReferences();
        $startAt = now('UTC')->addDays(5)->format('Y-m-d\TH:i:s.v\Z');
        $pendingId = (string) $this->withToken($token)
            ->postJson('/api/v1/organization/assignments', [
                'person_id' => $personId,
                'position_id' => $positionId,
                'start_at' => $startAt,
                'is_primary' => true,
            ], $this->writeHeaders('pending-create'))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.id');

        $cancelled = $this->withToken($token)
            ->postJson("/api/v1/organization/assignments/{$pendingId}/end", [
                'end_at' => $startAt,
                'reason' => 'إلغاء التكليف المستقبلي',
            ], $this->actionHeaders('"1"', 'pending-end'))
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.status', 'ended')
            ->assertJsonPath('data.end_at', $startAt)
            ->assertJsonPath('data.end_reason', 'إلغاء التكليف المستقبلي');
        $this->assertSame($pendingId, $cancelled->json('data.id'));

        $this->withToken($token)
            ->postJson("/api/v1/organization/assignments/{$pendingId}/end", [
                'end_at' => $startAt,
                'reason' => 'إلغاء التكليف المستقبلي',
            ], $this->actionHeaders('"1"', 'pending-end'))
            ->assertOk()
            ->assertHeader('ETag', '"2"');
        $this->withToken($token)
            ->postJson("/api/v1/organization/assignments/{$pendingId}/end", [
                'end_at' => $startAt,
                'reason' => 'محاولة ثانية',
            ], $this->actionHeaders('"2"', 'pending-end-again'))
            ->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/assignment-already-ended');

        $trimId = (string) $this->withToken($token)
            ->postJson('/api/v1/organization/assignments', [
                'person_id' => $personId,
                'position_id' => $positionId,
                'start_at' => $startAt,
                'end_at' => now('UTC')->addDays(10)->format('Y-m-d\TH:i:s.v\Z'),
            ], $this->writeHeaders('pending-trim-create'))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.id');
        $this->withToken($token)
            ->postJson("/api/v1/organization/assignments/{$trimId}/end", [
                'end_at' => now('UTC')->addDays(6)->format('Y-m-d\TH:i:s.v\Z'),
                'reason' => 'تقليص النافذة',
            ], $this->actionHeaders('"1"', 'pending-trim-end'))
            ->assertOk()
            ->assertJsonPath('data.status', 'ended')
            ->assertJsonPath('data.end_at', $startAt);
        $this->assertDatabaseHas('assignments', [
            'id' => $trimId,
            'end_at' => $this->databaseTimestamp($startAt),
            'end_reason' => 'تقليص النافذة',
        ]);
        $items = $this->withToken($token)
            ->getJson('/api/v1/organization/assignments?person_id='.$personId, $this->headers())
            ->assertOk()
            ->json('items');
        $this->assertSame('ended', collect($items)->firstWhere('id', $trimId)['status']);
    }

    public function test_pending_assignment_end_rejects_a_date_before_its_start(): void
    {
        [$token, $personId, $positionId] = $this->assignmentReferences();
        $startAt = now('UTC')->addDays(5)->format('Y-m-d\TH:i:s.v\Z');
        $pendingId = (string) $this->withToken($token)
            ->postJson('/api/v1/organization/assignments', [
                'person_id' => $personId,
                'position_id' => $positionId,
                'start_at' => $startAt,
            ], $this->writeHeaders('pending-before-start-create'))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.id');

        $this->withToken($token)
            ->postJson("/api/v1/organization/assignments/{$pendingId}/end", [
                'end_at' => now('UTC')->subDay()->format('Y-m-d\TH:i:s.v\Z'),
                'reason' => 'قبل البدء',
            ], $this->actionHeaders('"1"', 'pending-before-start'))
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-assignment-end');
        $this->assertDatabaseHas('assignments', ['id' => $pendingId, 'end_at' => null, 'lock_version' => 1]);
    }

    public function test_active_assignment_end_still_rejects_future_dates(): void
    {
        [$token, $personId, $positionId] = $this->assignmentReferences();
        $assignmentId = (string) $this->withToken($token)
            ->postJson('/api/v1/organization/assignments', $this->assignmentBody($personId, $positionId), $this->writeHeaders('active-future-create'))
            ->assertCreated()->json('data.id');
        $this->withToken($token)
            ->postJson("/api/v1/organization/assignments/{$assignmentId}/end", [
                'end_at' => now('UTC')->addHour()->format('Y-m-d\TH:i:s.v\Z'),
                'reason' => 'تاريخ مستقبلي',
            ], $this->actionHeaders('"1"', 'active-future-end'))
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-assignment-end');
        $this->assertDatabaseHas('assignments', ['id' => $assignmentId, 'lock_version' => 1, 'end_reason' => null]);
    }

    public function test_assignment_requests_are_authorized_and_roll_back_with_outbox(): void
    {
        [$token, $personId, $positionId] = $this->assignmentReferences();
        $other = $this->loginToken('fixture-account-b', 'fixture-password-b');
        $body = $this->assignmentBody($personId, $positionId);

        $this->withHeaders(['Authorization' => ''])
            ->postJson('/api/v1/organization/assignments', $body, $this->writeHeaders('assignment-anonymous'))
            ->assertUnauthorized();
        $this->withToken($other)->postJson('/api/v1/organization/assignments', $body, $this->writeHeaders('assignment-denied'))
            ->assertForbidden();
        $assignmentId = (string) $this->withToken($token)
            ->postJson('/api/v1/organization/assignments', $body, $this->writeHeaders('assignment-authorized'))
            ->assertCreated()->json('data.id');
        $this->withToken($other)
            ->getJson('/api/v1/organization/assignments', $this->headers())
            ->assertForbidden();
        $this->withToken($other)
            ->postJson("/api/v1/organization/assignments/{$assignmentId}/end", [
                'end_at' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
                'reason' => 'محاولة مرفوضة',
            ], $this->actionHeaders('"1"', 'assignment-end-denied'))
            ->assertForbidden();
        DB::table('assignments')->where('id', $assignmentId)->delete();

        $eventId = (string) DB::table('outbox_events')->value('event_id');
        try {
            $this->app->make(AssignmentHandler::class)->create(
                Str::uuid7()->toString(),
                $body,
                [
                    'principal_id' => '018f6f7d-0c00-7000-8000-000000000021',
                    'operation' => 'rollback-assignment',
                    'key_hash' => hash('sha256', 'rollback-assignment'),
                    'request_hash' => hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)),
                ],
                fn (): array => [
                    'id' => $eventId,
                    'type' => 'com.cluster.organization.assignmentstarted.v1',
                    'time' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
                    'data' => [],
                ],
            );
            $this->fail('The duplicate assignment event must fail the transaction.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', (string) $exception->getCode());
        }

        $this->assertDatabaseCount('assignments', 0);
        $this->assertDatabaseMissing('organization_idempotency_keys', ['operation' => 'rollback-assignment']);
    }

    /** @return array{string, string, string} */
    private function assignmentReferences(): array
    {
        $token = $this->loginToken();
        $clusterId = $this->createCluster($token);
        $unitId = $this->createUnit($token, $clusterId);

        return [
            $token,
            $this->createPerson($token, 'EMP-ASSIGN-001', 'assignment-person-1'),
            $this->createPosition($token, $unitId, 'PRIMARY', 'المنصب الأساسي'),
        ];
    }

    /** @return array<string, mixed> */
    private function assignmentBody(string $personId, string $positionId): array
    {
        return [
            'person_id' => $personId,
            'position_id' => $positionId,
            'start_at' => now('UTC')->subHour()->format('Y-m-d\TH:i:s.v\Z'),
            'end_at' => now('UTC')->addDay()->format('Y-m-d\TH:i:s.v\Z'),
            'is_primary' => true,
        ];
    }

    private function createCluster(string $token): string
    {
        return (string) $this->withToken($token)->postJson('/api/v1/organization/cluster', [
            'code' => 'THC3',
            'name' => 'التجمع الصحي الثالث',
        ], $this->writeHeaders('assignment-cluster'))->assertCreated()->json('data.id');
    }

    private function createUnit(string $token, string $clusterId): string
    {
        return (string) $this->withToken($token)->postJson('/api/v1/organization/units', [
            'cluster_id' => $clusterId,
            'type_code' => 'department',
            'code' => 'ASSIGNMENTS',
            'name' => 'إدارة التكليفات',
        ], $this->writeHeaders('assignment-unit'))->assertCreated()->json('data.id');
    }

    private function createPosition(string $token, string $unitId, string $code, string $title): string
    {
        return (string) $this->withToken($token)->postJson('/api/v1/organization/positions', [
            'organization_unit_id' => $unitId,
            'code' => $code,
            'title' => $title,
        ], $this->writeHeaders('assignment-position-'.$code))->assertCreated()->json('data.id');
    }

    private function createPerson(string $token, string $employeeNumber, string $key): string
    {
        return (string) $this->withToken($token)->postJson('/api/v1/organization/people', [
            'employee_number' => $employeeNumber,
            'display_name_ar' => 'موظف التكليف',
            'status' => 'active',
        ], $this->writeHeaders($key))->assertCreated()->json('data.id');
    }

    private function unitId(string $positionId): string
    {
        return (string) DB::table('positions')->where('id', $positionId)->value('organization_unit_id');
    }

    private function loginToken(string $username = 'fixture-account-a', string $password = 'fixture-password-a'): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'username' => $username,
            'password' => $password,
        ], $this->headers())->assertOk()->json('data.access_token');
    }

    private function databaseTimestamp(string $value): string
    {
        return \Carbon\CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s.v');
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
}
