<?php

declare(strict_types=1);

namespace Modules\Tasks\Tests;

use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Organization\Contracts\ResolveScopeDescendants;
use Tests\TestCase;

/**
 * Per-principal visibility, participant limits, and out-of-team rejection.
 * Visibility is the contract: a creator/assignee/participant may see the
 * task; anyone else gets a non-disclosing 404 on show and the row never
 * surfaces in list. Participants may comment/attach but not transition or
 * reassign.
 */
final class TasksPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION = '018f6f7d-0c00-7000-8000-000000000602';

    private const USER_A = '018f6f7d-0c00-7000-8000-000000000021';

    private const USER_B = '018f6f7d-0c00-7000-8000-000000000022';

    private const USER_IN_SCOPE = '018f6f7d-0c00-7000-8000-000000000023';

    private const FACILITY_A = '018f6f7d-0c00-7000-8000-000000000011';

    private const FACILITY_B = '018f6f7d-0c00-7000-8000-000000000012';

    private const UNIT_A = '018f6f7d-0c00-7000-8000-000000000041';

    private const UNIT_B = '018f6f7d-0c00-7000-8000-000000000042';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DevelopmentJourneyAuthorizationSeeder::class);
        $this->token = (string) $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'fixture-password-a',
        ], ['X-Correlation-ID' => self::CORRELATION])->assertOk()->json('data.access_token');
    }

    private function seedTask(string $assignee, string $creator, string $state = 'open'): string
    {
        $id = (string) Str::uuid7();
        DB::table('tasks')->insert([
            'id' => $id,
            'title' => 'Permission task',
            'description' => null,
            'created_by_user_id' => $creator,
            'assignee_user_id' => $assignee,
            'owner_organization_unit_id' => self::FACILITY_A,
            'status' => $state,
            'priority' => 'normal',
            'classification' => 'internal',
            'completion_policy' => 'direct',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedParticipant(string $taskId, string $userId): void
    {
        DB::table('task_participants')->insert([
            'id' => (string) Str::uuid7(),
            'task_id' => $taskId,
            'user_id' => $userId,
            'role' => 'participant',
            'added_by_user_id' => self::USER_A,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedTaskOwnedBy(string $ownerId): string
    {
        $taskId = $this->seedTask(self::USER_B, self::USER_B);
        DB::table('tasks')->where('id', $taskId)->update(['owner_organization_unit_id' => $ownerId]);

        return $taskId;
    }

    /** @param list<string> $deniedRecordIds */
    private function bindScopeTaskListAccess(
        string $allowedScopeId,
        bool $allowRequestedScope = true,
        array $deniedRecordIds = [],
    ): ScopeTaskListAccess {
        $access = new ScopeTaskListAccess($allowedScopeId, $allowRequestedScope, $deniedRecordIds);
        $this->app->instance(DecideAccess::class, $access);

        return $access;
    }

    private function seedTaskWithIdOwnerAndCreatedAt(string $taskId, string $ownerId, string $createdAt): void
    {
        DB::table('tasks')->insert([
            'id' => $taskId,
            'title' => 'Paginated scope task',
            'description' => null,
            'created_by_user_id' => self::USER_B,
            'assignee_user_id' => self::USER_B,
            'owner_organization_unit_id' => $ownerId,
            'status' => 'open',
            'priority' => 'normal',
            'classification' => 'internal',
            'completion_policy' => 'direct',
            'lock_version' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    public function test_show_returns_404_for_unrelated_user(): void
    {
        $taskId = $this->seedTask(self::USER_B, self::USER_B);

        $resp = $this->withToken($this->token)->getJson('/api/v1/tasks/'.$taskId, [
            'X-Correlation-ID' => self::CORRELATION,
        ]);
        $resp->assertNotFound();
    }

    public function test_list_only_returns_tasks_visible_to_principal(): void
    {
        $mine = $this->seedTask(self::USER_A, self::USER_A);
        $theirs = $this->seedTask(self::USER_B, self::USER_B);

        $resp = $this->withToken($this->token)->getJson('/api/v1/tasks', [
            'X-Correlation-ID' => self::CORRELATION,
        ]);
        $resp->assertOk();
        $ids = array_column($resp->json('items'), 'id');
        $this->assertContains($mine, $ids);
        $this->assertNotContains($theirs, $ids);
    }

    public function test_list_removes_related_task_when_tasks_read_is_explicitly_denied(): void
    {
        $mine = $this->seedTask(self::USER_A, self::USER_A);
        $this->bindRealAccessDecision();
        $this->denyJourneyCapability('tasks.read');

        $response = $this->withToken($this->token)->getJson('/api/v1/tasks', [
            'X-Correlation-ID' => self::CORRELATION,
        ]);

        $response->assertOk();
        $this->assertNotContains($mine, array_column($response->json('items'), 'id'));
    }

    public function test_list_filter_by_relationship_assigned(): void
    {
        $mine = $this->seedTask(self::USER_A, self::USER_B);
        $theirs = $this->seedTask(self::USER_B, self::USER_B);

        $resp = $this->withToken($this->token)->getJson('/api/v1/tasks?relationship=assigned', [
            'X-Correlation-ID' => self::CORRELATION,
        ]);
        $resp->assertOk();
        $ids = array_column($resp->json('items'), 'id');
        $this->assertContains($mine, $ids);
        $this->assertNotContains($theirs, $ids);
    }

    public function test_authorized_list_honors_requested_limit_after_overfetch(): void
    {
        $this->seedTask(self::USER_A, self::USER_A);
        $this->seedTask(self::USER_A, self::USER_A);
        $this->seedTask(self::USER_A, self::USER_A);

        $response = $this->withToken($this->token)->getJson('/api/v1/tasks?limit=1', [
            'X-Correlation-ID' => self::CORRELATION,
        ]);

        $response->assertOk()->assertJsonCount(1, 'items');
        $this->assertIsString($response->json('next_cursor'));
    }

    public function test_list_filter_by_state(): void
    {
        $open = $this->seedTask(self::USER_A, self::USER_A, 'open');
        $inProgress = $this->seedTask(self::USER_A, self::USER_A, 'in_progress');

        $resp = $this->withToken($this->token)->getJson('/api/v1/tasks?state=in_progress', [
            'X-Correlation-ID' => self::CORRELATION,
        ]);
        $resp->assertOk();
        $ids = array_column($resp->json('items'), 'id');
        $this->assertContains($inProgress, $ids);
        $this->assertNotContains($open, $ids);
    }

    public function test_scope_task_list_for_a_facility_includes_the_facility_and_its_descendant_units_only(): void
    {
        $directFacilityTask = $this->seedTaskOwnedBy(self::FACILITY_A);
        $descendantUnitTask = $this->seedTaskOwnedBy(self::UNIT_A);
        $otherFacilityTask = $this->seedTaskOwnedBy(self::FACILITY_B);
        $otherUnitTask = $this->seedTaskOwnedBy(self::UNIT_B);
        $access = $this->bindScopeTaskListAccess(self::FACILITY_A);

        $response = $this->withToken($this->token)->getJson('/api/v1/tasks?view=scope&scope_type=facility&scope_id='.self::FACILITY_A, [
            'X-Correlation-ID' => self::CORRELATION,
        ]);

        $response->assertOk();
        $ids = array_column($response->json('items'), 'id');
        $this->assertContains($directFacilityTask, $ids);
        $this->assertContains($descendantUnitTask, $ids);
        $this->assertNotContains($otherFacilityTask, $ids);
        $this->assertNotContains($otherUnitTask, $ids);
        $this->assertSame(self::FACILITY_A, $access->requestedScopeFacts[0]->ownerFacilityId);
        $this->assertNull($access->requestedScopeFacts[0]->organizationUnitId);
    }

    public function test_scope_task_list_for_a_cluster_includes_tasks_from_each_descendant_facility(): void
    {
        $clusterId = (string) DB::table('clusters')->where('singleton_key', 1)->value('id');
        $facilityATask = $this->seedTaskOwnedBy(self::FACILITY_A);
        $facilityBTask = $this->seedTaskOwnedBy(self::FACILITY_B);
        $access = $this->bindScopeTaskListAccess($clusterId);

        $response = $this->withToken($this->token)->getJson('/api/v1/tasks?view=scope&scope_type=cluster&scope_id='.$clusterId, [
            'X-Correlation-ID' => self::CORRELATION,
        ]);

        $response->assertOk();
        $ids = array_column($response->json('items'), 'id');
        $this->assertContains($facilityATask, $ids);
        $this->assertContains($facilityBTask, $ids);
        $this->assertSame($clusterId, $access->requestedScopeFacts[0]->clusterId);
    }

    public function test_scope_task_list_for_a_cluster_includes_a_direct_cluster_owned_task_with_cluster_facts(): void
    {
        $clusterId = (string) DB::table('clusters')->where('singleton_key', 1)->value('id');
        $clusterTask = $this->seedTaskOwnedBy($clusterId);
        $access = $this->bindScopeTaskListAccess($clusterId);

        $response = $this->withToken($this->token)->getJson('/api/v1/tasks?view=scope&scope_type=cluster&scope_id='.$clusterId, [
            'X-Correlation-ID' => self::CORRELATION,
        ]);

        $response->assertOk();
        $this->assertContains($clusterTask, array_column($response->json('items'), 'id'));
        $rowFacts = collect($access->rowFacts)->firstWhere('recordId', $clusterTask);
        $this->assertInstanceOf(RecordFacts::class, $rowFacts);
        $this->assertSame($clusterId, $rowFacts->clusterId);
        $this->assertNull($rowFacts->ownerFacilityId);
        $this->assertNull($rowFacts->organizationUnitId);
    }

    public function test_scope_task_list_for_a_unit_includes_only_that_exact_unit(): void
    {
        $requestedUnitTask = $this->seedTaskOwnedBy(self::UNIT_A);
        $siblingUnitTask = $this->seedTaskOwnedBy(self::UNIT_B);
        $facilityTask = $this->seedTaskOwnedBy(self::FACILITY_A);
        $access = $this->bindScopeTaskListAccess(self::UNIT_A);

        $response = $this->withToken($this->token)->getJson('/api/v1/tasks?view=scope&scope_type=unit&scope_id='.self::UNIT_A, [
            'X-Correlation-ID' => self::CORRELATION,
        ]);

        $response->assertOk();
        $ids = array_column($response->json('items'), 'id');
        $this->assertContains($requestedUnitTask, $ids);
        $this->assertNotContains($siblingUnitTask, $ids);
        $this->assertNotContains($facilityTask, $ids);
        $this->assertSame(self::UNIT_A, $access->requestedScopeFacts[0]->organizationUnitId);
    }

    public function test_scope_task_list_uses_real_rbac_for_allowed_and_cross_facility_requests(): void
    {
        $facilityATask = $this->seedTaskOwnedBy(self::FACILITY_A);
        $facilityBTask = $this->seedTaskOwnedBy(self::FACILITY_B);
        $this->bindRealAccessDecision();

        $allowed = $this->withToken($this->token)->getJson('/api/v1/tasks?view=scope&scope_type=facility&scope_id='.self::FACILITY_A, [
            'X-Correlation-ID' => self::CORRELATION,
        ]);

        $allowed->assertOk();
        $allowedIds = array_column($allowed->json('items'), 'id');
        $this->assertContains($facilityATask, $allowedIds);
        $this->assertNotContains($facilityBTask, $allowedIds);

        $this->withToken($this->token)->getJson('/api/v1/tasks?view=scope&scope_type=facility&scope_id='.self::FACILITY_B, [
            'X-Correlation-ID' => self::CORRELATION,
        ])->assertForbidden();
    }

    public function test_scope_task_list_denies_the_requested_scope_before_querying_tasks_even_when_empty(): void
    {
        $access = $this->bindScopeTaskListAccess(self::FACILITY_B, false);
        $taskListQueries = [];
        DB::listen(static function ($query) use (&$taskListQueries): void {
            if (str_contains($query->sql, '"tasks" as "t"')) {
                $taskListQueries[] = $query->sql;
            }
        });

        $response = $this->withToken($this->token)->getJson('/api/v1/tasks?view=scope&scope_type=facility&scope_id='.self::FACILITY_B, [
            'X-Correlation-ID' => self::CORRELATION,
        ]);

        $response->assertForbidden();
        $this->assertCount(1, $access->requestedScopeFacts);
        $this->assertSame([], $taskListQueries);
    }

    public function test_scope_task_list_excludes_a_candidate_denied_by_its_record_facts(): void
    {
        $allowedTask = $this->seedTaskOwnedBy(self::FACILITY_A);
        $deniedTask = $this->seedTaskOwnedBy(self::UNIT_A);
        $access = $this->bindScopeTaskListAccess(self::FACILITY_A, true, [$deniedTask]);

        $response = $this->withToken($this->token)->getJson('/api/v1/tasks?view=scope&scope_type=facility&scope_id='.self::FACILITY_A, [
            'X-Correlation-ID' => self::CORRELATION,
        ]);

        $response->assertOk();
        $ids = array_column($response->json('items'), 'id');
        $this->assertContains($allowedTask, $ids);
        $this->assertNotContains($deniedTask, $ids);
        $this->assertEqualsCanonicalizing(
            [$allowedTask, $deniedTask],
            array_column($access->rowFacts, 'recordId'),
        );
    }

    public function test_scope_task_list_cursor_pages_by_uuid_without_gaps_or_duplicates_when_created_at_is_inverted(): void
    {
        $lowerId = '018f6f7d-0c00-7000-8000-000000000701';
        $higherId = '018f6f7d-0c00-7000-8000-000000000702';
        $this->seedTaskWithIdOwnerAndCreatedAt($lowerId, self::FACILITY_A, '2026-08-13 12:00:00');
        $this->seedTaskWithIdOwnerAndCreatedAt($higherId, self::FACILITY_A, '2026-08-13 11:00:00');
        $this->bindScopeTaskListAccess(self::FACILITY_A);
        $baseUrl = '/api/v1/tasks?view=scope&scope_type=facility&scope_id='.self::FACILITY_A.'&limit=1';

        $first = $this->withToken($this->token)->getJson($baseUrl, [
            'X-Correlation-ID' => self::CORRELATION,
        ])->assertOk()->assertJsonCount(1, 'items');
        $cursor = $first->json('next_cursor');
        $this->assertIsString($cursor);

        $second = $this->withToken($this->token)->getJson($baseUrl.'&cursor='.$cursor, [
            'X-Correlation-ID' => self::CORRELATION,
        ])->assertOk()->assertJsonCount(1, 'items');

        $ids = [$first->json('items.0.id'), $second->json('items.0.id')];
        $this->assertSame([$lowerId, $higherId], $ids);
        $this->assertCount(2, array_unique($ids));
        $this->assertNull($second->json('next_cursor'));
    }

    public function test_scope_task_list_rejects_missing_extra_or_relationship_scope_parameters(): void
    {
        foreach ([
            '/api/v1/tasks?view=scope&scope_type=facility',
            '/api/v1/tasks?scope_type=facility&scope_id='.self::FACILITY_A,
            '/api/v1/tasks?view=scope&scope_type=facility&scope_id='.self::FACILITY_A.'&relationship=assigned',
        ] as $url) {
            $this->withToken($this->token)->getJson($url, [
                'X-Correlation-ID' => self::CORRELATION,
            ])->assertStatus(400);
        }
    }

    public function test_participant_can_view_and_comment_but_cannot_transition(): void
    {
        // The caller (account A) is a participant on a task owned by B.
        $taskId = $this->seedTask(self::USER_B, self::USER_B);
        $this->seedParticipant($taskId, self::USER_A);
        $show = $this->withToken($this->token)->getJson('/api/v1/tasks/'.$taskId, [
            'X-Correlation-ID' => self::CORRELATION,
        ]);
        $show->assertOk();

        $comment = $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/comments', [
            'body' => 'Seen',
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-part-comment-'.Str::uuid7()->toString(),
        ]);
        $comment->assertStatus(201);

        $start = $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/start', [], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-part-start-'.Str::uuid7()->toString(),
            'If-Match' => '"1"',
        ]);
        $start->assertStatus(403);
    }

    public function test_create_assigns_to_another_user_requires_in_scope_target(): void
    {
        // fixture-account-b is in FACILITY_B (out of scope for fixture-account-a in FACILITY_A)
        $resp = $this->withToken($this->token)->postJson('/api/v1/tasks', [
            'title' => 'Cross team task',
            'assignee_user_id' => self::USER_B,
            'priority' => 'normal',
            'classification' => 'internal',
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-cross-'.Str::uuid7()->toString(),
        ]);
        $resp->assertStatus(422);
    }

    public function test_create_rejects_participant_outside_the_task_facility_scope(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/v1/tasks', [
            'title' => 'Scoped participants',
            'participant_user_ids' => [self::USER_B],
            'priority' => 'normal',
            'classification' => 'internal',
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-participant-scope-'.Str::uuid7()->toString(),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('tasks')->where('title', 'Scoped participants')->count());
    }

    public function test_cluster_scoped_actor_cannot_assign_a_facility_b_task_to_a_facility_a_user(): void
    {
        $this->grantActorClusterScope();
        $response = $this->withToken($this->token)->postJson('/api/v1/tasks', [
            'title' => 'Wrong owner-facility assignee',
            'owner_organization_unit_id' => self::FACILITY_B,
            'assignee_user_id' => self::USER_A,
            'priority' => 'normal',
            'classification' => 'internal',
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-owner-assignee-'.Str::uuid7()->toString(),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('tasks')->where('title', 'Wrong owner-facility assignee')->count());
    }

    public function test_cluster_scoped_creator_is_not_skipped_when_listed_as_an_out_of_facility_participant(): void
    {
        $this->grantActorClusterScope();
        $response = $this->withToken($this->token)->postJson('/api/v1/tasks', [
            'title' => 'Wrong owner-facility participant',
            'owner_organization_unit_id' => self::FACILITY_B,
            'assignee_user_id' => self::USER_B,
            'participant_user_ids' => [self::USER_A],
            'priority' => 'normal',
            'classification' => 'internal',
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-owner-participant-'.Str::uuid7()->toString(),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('tasks')->where('title', 'Wrong owner-facility participant')->count());
    }

    public function test_cluster_scoped_actor_cannot_reassign_a_facility_b_task_to_a_facility_a_user(): void
    {
        $this->grantActorClusterScope();
        $taskId = $this->seedTask(self::USER_B, self::USER_A);
        DB::table('tasks')->where('id', $taskId)->update(['owner_organization_unit_id' => self::FACILITY_B]);

        $response = $this->withToken($this->token)->patchJson('/api/v1/tasks/'.$taskId, [
            'assignee_user_id' => self::USER_A,
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'If-Match' => '"1"',
        ]);

        $response->assertStatus(422);
        $this->assertSame(self::USER_B, DB::table('tasks')->where('id', $taskId)->value('assignee_user_id'));
    }

    public function test_enrollment_rejects_participant_outside_the_task_facility_scope(): void
    {
        $taskId = $this->seedTask(self::USER_A, self::USER_A);

        $response = $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/participants', [
            'user_id' => self::USER_B,
            'role' => 'participant',
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-enrollment-scope-'.Str::uuid7()->toString(),
            'If-Match' => '"1"',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('task_participants')->where('task_id', $taskId)->count());
    }

    public function test_enrollment_accepts_active_participant_inside_the_task_facility_scope(): void
    {
        $taskId = $this->seedTask(self::USER_A, self::USER_A);

        $response = $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/participants', [
            'user_id' => self::USER_IN_SCOPE,
            'role' => 'participant',
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-enrollment-in-scope-'.Str::uuid7()->toString(),
            'If-Match' => '"1"',
        ]);

        $response->assertOk();
        $this->assertSame(1, DB::table('task_participants')->where('task_id', $taskId)->where('user_id', self::USER_IN_SCOPE)->count());
    }

    private function denyJourneyCapability(string $capability): void
    {
        DB::table('explicit_denies')->insert([
            'id' => Str::uuid7()->toString(),
            'user_id' => self::USER_A,
            'capability_code' => $capability,
            'classification' => null,
            'organization_unit_id' => null,
            'resource_pattern' => 'task*',
            'reason' => 'Task regression test deny.',
            'issued_by_user_id' => self::USER_A,
            'issued_at' => now()->subMinute(),
            'expires_at' => null,
            'revocable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function grantActorClusterScope(): void
    {
        $clusterId = DB::table('clusters')->where('singleton_key', 1)->value('id');
        DB::table('role_assignments')->where('user_id', self::USER_A)->update([
            'scope_type' => 'cluster',
            'scope_id' => $clusterId,
        ]);
        $this->app->instance(DecideAccess::class, new AllowAllTaskAccess);
        $this->app->instance(ResolveScopeDescendants::class, new class implements ResolveScopeDescendants
        {
            public function descendants(string $scopeType, string $scopeId): array
            {
                return $scopeType === 'facility' && $scopeId === '018f6f7d-0c00-7000-8000-000000000011'
                    ? [['scope_type' => 'facility', 'scope_id' => '018f6f7d-0c00-7000-8000-000000000012']]
                    : [];
            }
        });
    }
}

final class AllowAllTaskAccess implements DecideAccess
{
    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return new AccessDecision('allow', $capability, $facts->resourceType, [], 'test', 'test', $facts->classification);
    }
}

final class ScopeTaskListAccess implements DecideAccess
{
    /** @var list<RecordFacts> */
    public array $requestedScopeFacts = [];

    /** @var list<RecordFacts> */
    public array $rowFacts = [];

    /** @param list<string> $deniedRecordIds */
    public function __construct(
        private readonly string $allowedScopeId,
        private readonly bool $allowRequestedScope = true,
        private readonly array $deniedRecordIds = [],
    ) {}

    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        if ($capability === 'tasks.read' && $facts?->recordId === $this->allowedScopeId) {
            $this->requestedScopeFacts[] = $facts;

            return $this->decision($capability, $facts, $this->allowRequestedScope);
        }

        if ($capability === 'tasks.read' && $facts !== null) {
            $this->rowFacts[] = $facts;

            return $this->decision(
                $capability,
                $facts,
                ! in_array($facts->recordId, $this->deniedRecordIds, true),
            );
        }

        if ($facts === null) {
            return new AccessDecision(
                'allow',
                $capability,
                'task',
                [],
                'test',
                'test',
                'internal',
            );
        }

        return $this->decision($capability, $facts, true);
    }

    private function decision(string $capability, RecordFacts $facts, bool $allowed): AccessDecision
    {
        return new AccessDecision(
            $allowed ? 'allow' : 'deny',
            $capability,
            $facts->resourceType,
            [],
            'test',
            'test',
            $facts->classification,
        );
    }
}
