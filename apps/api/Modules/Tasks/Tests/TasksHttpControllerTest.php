<?php

declare(strict_types=1);

namespace Modules\Tasks\Tests;

use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Contracts\ListUserDisplayLabels;
use Modules\Organization\Contracts\ListOrganizationScopeTargets;
use Shared\Contracts\TransactionalOutbox;
use Tests\TestCase;

/**
 * Wire-level coverage of the public /tasks surface: correlation headers,
 * ETag propagation, payload validation, store/show/update paths. Lifecycle
 * transitions are covered by TasksLifecycleTest; permissions in
 * TasksPermissionsTest; notifications in TasksNotificationsTest.
 */
final class TasksHttpControllerTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION = '018f6f7d-0c00-7000-8000-000000000605';

    private const USER_A = '018f6f7d-0c00-7000-8000-000000000021';

    private const USER_B = '018f6f7d-0c00-7000-8000-000000000022';

    private const USER_IN_SCOPE = '018f6f7d-0c00-7000-8000-000000000023';

    private const FACILITY_A = '018f6f7d-0c00-7000-8000-000000000011';

    private const UNIT_A = '018f6f7d-0c00-7000-8000-000000000041';

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

    private function seedTask(string $assignee, string $state = 'open', ?string $creator = null): string
    {
        $id = (string) Str::uuid7();
        DB::table('tasks')->insert([
            'id' => $id,
            'title' => 'Seeded',
            'description' => null,
            'created_by_user_id' => $creator ?? self::USER_A,
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

    public function test_index_returns_assignee_tasks(): void
    {
        $mine = $this->seedTask(self::USER_A);
        // Created by me but assigned away: visible through the creator relationship.
        $createdByMe = $this->seedTask(self::USER_B);
        // Created and assigned elsewhere: never surfaces.
        $other = $this->seedTask(self::USER_B, 'open', self::USER_B);

        $resp = $this->withToken($this->token)->getJson('/api/v1/tasks', [
            'X-Correlation-ID' => self::CORRELATION,
        ]);
        $resp->assertOk();

        $ids = array_column($resp->json('items'), 'id');
        $this->assertContains($mine, $ids);
        $this->assertContains($createdByMe, $ids);
        $this->assertNotContains($other, $ids);
    }

    public function test_index_lists_only_active_tasks_read_assignment_roots_as_available_scopes(): void
    {
        $taskRoleId = DB::table('roles')
            ->where('code', DevelopmentJourneyAuthorizationSeeder::ROLE_CODE)
            ->value('id');
        $this->assertIsString($taskRoleId);

        DB::table('role_assignments')
            ->where('user_id', self::USER_A)
            ->where('role_id', $taskRoleId)
            ->update([
                'scope_type' => 'unit',
                'scope_id' => self::UNIT_A,
                'updated_at' => now(),
            ]);

        $response = $this->withToken($this->token)->getJson('/api/v1/tasks', [
            'X-Correlation-ID' => self::CORRELATION,
        ])->assertOk();

        $this->assertSame([
            [
                'scope_type' => 'unit',
                'scope_id' => self::UNIT_A,
                'label' => 'وحدة اختبار W1.3',
            ],
        ], $response->json('available_scopes'));
    }

    public function test_task_list_returns_human_display_labels_for_its_people_and_owner_scope(): void
    {
        $taskId = $this->seedTask(self::USER_B);

        $response = $this->withToken($this->token)->getJson('/api/v1/tasks', [
            'X-Correlation-ID' => self::CORRELATION,
        ])->assertOk();

        $item = collect($response->json('items'))->firstWhere('id', $taskId);
        $this->assertIsArray($item);
        $this->assertSame(self::USER_B, $item['assignee_user_id']);
        $this->assertSame('حساب اختبار W1.3 ب', $item['assignee']['display_name']);
        $this->assertSame(self::USER_A, $item['creator_user_id']);
        $this->assertSame('حساب اختبار W1.3 أ', $item['creator']['display_name']);
        $this->assertSame('facility', $item['owner_scope']['scope_type']);
        $this->assertSame(self::FACILITY_A, $item['owner_scope']['scope_id']);
        $this->assertSame('منشأة اختبار W1.3 أ', $item['owner_scope']['label']);
        $this->assertSame('w13-e2e-facility-a', $item['owner_scope']['code']);
    }

    public function test_task_list_contract_and_payload_allow_null_description_and_due_at(): void
    {
        $contract = file_get_contents(dirname(base_path(), 2).'/docs/contracts/api/openapi.yaml');
        $this->assertNotFalse($contract);
        $this->assertMatchesRegularExpression(
            '/Task:\\R(?:(?!^    [A-Za-z][A-Za-z0-9_]*:).)*?^        description:\\R          anyOf:\\R          - type: string\\R            maxLength: 4000\\R          - type: \\x27null\\x27/ms',
            $contract,
        );
        $this->assertMatchesRegularExpression(
            '/Task:\\R(?:(?!^    [A-Za-z][A-Za-z0-9_]*:).)*?^        due_at:\\R          anyOf:\\R          - \\$ref: \\x27#\\/components\\/schemas\\/UtcDateTime\\x27\\R          - type: \\x27null\\x27/ms',
            $contract,
        );

        $taskId = $this->seedTask(self::USER_B);
        $item = collect($this->withToken($this->token)->getJson('/api/v1/tasks', [
            'X-Correlation-ID' => self::CORRELATION,
        ])->assertOk()->json('items'))->firstWhere('id', $taskId);

        $this->assertIsArray($item);
        $this->assertNull($item['description']);
        $this->assertNull($item['due_at']);
    }

    public function test_task_list_batches_user_label_lookup_and_falls_back_to_the_user_id_for_blank_labels(): void
    {
        $labels = new CountingUserDisplayLabels([
            self::USER_A => 'اسم المنشئ',
            self::USER_B => '   ',
        ]);
        $scopeLabels = new CountingOrganizationScopeLabels;
        $this->app->instance(ListUserDisplayLabels::class, $labels);
        $this->app->instance(ListOrganizationScopeTargets::class, $scopeLabels);
        $taskId = $this->seedTask(self::USER_B);

        $response = $this->withToken($this->token)->getJson('/api/v1/tasks', [
            'X-Correlation-ID' => self::CORRELATION,
        ])->assertOk();

        $item = collect($response->json('items'))->firstWhere('id', $taskId);
        $this->assertIsArray($item);
        $this->assertSame('اسم المنشئ', $item['creator']['display_name']);
        $this->assertSame(self::USER_B, $item['assignee']['display_name']);
        $this->assertSame(1, $labels->calls);
        $this->assertEqualsCanonicalizing([self::USER_A, self::USER_B], $labels->requestedIds);
        $this->assertSame(1, $scopeLabels->calls);
        $this->assertSame(['facility'], $scopeLabels->scopeTypes);
    }

    public function test_task_list_falls_back_to_owner_scope_id_when_organization_omits_the_label(): void
    {
        $scopeLabels = new CountingOrganizationScopeLabels(false);
        $this->app->instance(ListOrganizationScopeTargets::class, $scopeLabels);
        $taskId = $this->seedTask(self::USER_B);

        $response = $this->withToken($this->token)->getJson('/api/v1/tasks', [
            'X-Correlation-ID' => self::CORRELATION,
        ])->assertOk();

        $item = collect($response->json('items'))->firstWhere('id', $taskId);
        $this->assertIsArray($item);
        $this->assertSame(self::FACILITY_A, $item['owner_scope']['label']);
        $this->assertSame(1, $scopeLabels->calls);
        $this->assertSame(['facility'], $scopeLabels->scopeTypes);
    }

    public function test_store_creates_task_with_201_and_etag(): void
    {
        $resp = $this->withToken($this->token)->postJson('/api/v1/tasks', [
            'title' => 'New task',
            'priority' => 'high',
            'classification' => 'internal',
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-store-'.Str::uuid7()->toString(),
        ]);

        $resp->assertStatus(201)->assertHeader('ETag', '"1"');
        $this->assertSame('New task', DB::table('tasks')->where('id', $resp->json('data.id'))->value('title'));
    }

    public function test_store_persists_the_authoritative_facility_owner_when_ownership_is_omitted(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/v1/tasks', [
            'title' => 'Facility-owned task',
            'priority' => 'normal',
            'classification' => 'internal',
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-canonical-owner-'.Str::uuid7()->toString(),
        ])->assertCreated();

        $taskId = (string) $response->json('data.id');
        $this->assertSame(self::FACILITY_A, DB::table('tasks')->where('id', $taskId)->value('owner_organization_unit_id'));
        $this->withToken($this->token)->getJson('/api/v1/tasks/'.$taskId, [
            'X-Correlation-ID' => self::CORRELATION,
        ])->assertOk()->assertJsonPath('data.id', $taskId);
    }

    public function test_store_rejects_invalid_payload(): void
    {
        $resp = $this->withToken($this->token)->postJson('/api/v1/tasks', [
            'title' => '',
            'priority' => 'urgent',
            'classification' => 'top-secret-x',
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-bad-'.Str::uuid7()->toString(),
        ]);

        $resp->assertStatus(422);
    }

    public function test_show_returns_404_for_other_users_task(): void
    {
        $otherTask = $this->seedTask(self::USER_B, 'open', self::USER_B);

        $resp = $this->withToken($this->token)->getJson('/api/v1/tasks/'.$otherTask, [
            'X-Correlation-ID' => self::CORRELATION,
        ]);
        $resp->assertNotFound();
    }

    public function test_update_requires_if_match_and_increments_lock_version(): void
    {
        $taskId = $this->seedTask(self::USER_A);

        $missing = $this->withToken($this->token)->patchJson('/api/v1/tasks/'.$taskId, ['title' => 'X'], [
            'X-Correlation-ID' => self::CORRELATION,
        ]);
        $missing->assertStatus(412);

        $ok = $this->withToken($this->token)->patchJson('/api/v1/tasks/'.$taskId, ['title' => 'Renamed'], [
            'X-Correlation-ID' => self::CORRELATION,
            'If-Match' => '"1"',
        ]);
        $ok->assertOk()->assertHeader('ETag', '"2"');
        $this->assertSame('Renamed', DB::table('tasks')->where('id', $taskId)->value('title'));
    }

    public function test_creator_relationship_cannot_bypass_tasks_update_denial(): void
    {
        $taskId = $this->seedTask(self::USER_A);
        $this->bindRealAccessDecision();
        $this->denyJourneyCapability('tasks.update');

        $response = $this->withToken($this->token)->patchJson('/api/v1/tasks/'.$taskId, ['title' => 'Denied rename'], [
            'X-Correlation-ID' => self::CORRELATION,
            'If-Match' => '"1"',
        ]);

        $response->assertForbidden();
        $this->assertSame('Seeded', DB::table('tasks')->where('id', $taskId)->value('title'));
    }

    public function test_noop_assignee_field_still_requires_tasks_assign(): void
    {
        $taskId = $this->seedTask(self::USER_A);
        $this->bindRealAccessDecision();
        $this->denyJourneyCapability('tasks.assign');

        $response = $this->withToken($this->token)->patchJson('/api/v1/tasks/'.$taskId, [
            'assignee_user_id' => self::USER_A,
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'If-Match' => '"1"',
        ]);

        $response->assertForbidden();
        $this->assertSame(1, (int) DB::table('tasks')->where('id', $taskId)->value('lock_version'));
    }

    public function test_reassignment_appends_the_canonical_task_assigned_event(): void
    {
        $taskId = $this->seedTask(self::USER_A);

        $this->withToken($this->token)->patchJson('/api/v1/tasks/'.$taskId, [
            'assignee_user_id' => self::USER_IN_SCOPE,
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'If-Match' => '"1"',
        ])->assertOk();

        $event = DB::table('outbox_events')->where('aggregate_id', $taskId)->sole();
        $this->assertSame('com.cluster.tasks.assigned.v1', $event->event_type);
        $envelope = json_decode((string) $event->cloud_event, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::USER_A, $envelope['data']['previous_assignee_user_id']);
        $this->assertSame(self::USER_IN_SCOPE, $envelope['data']['assignee_user_id']);
    }

    public function test_reassignment_rolls_back_when_task_assigned_outbox_append_fails(): void
    {
        $taskId = $this->seedTask(self::USER_A);
        $this->app->instance(TransactionalOutbox::class, new FailingReassignmentOutbox);
        $this->withoutExceptionHandling();

        try {
            $this->withToken($this->token)->patchJson('/api/v1/tasks/'.$taskId, [
                'assignee_user_id' => self::USER_IN_SCOPE,
            ], [
                'X-Correlation-ID' => self::CORRELATION,
                'If-Match' => '"1"',
            ]);
            $this->fail('Expected the TaskAssigned outbox append to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('task outbox unavailable', $exception->getMessage());
        }

        $row = DB::table('tasks')->where('id', $taskId)->first();
        $this->assertSame(self::USER_A, $row->assignee_user_id);
        $this->assertSame(1, (int) $row->lock_version);
        $this->assertSame(0, DB::table('notifications')->where('source_record_id', $taskId)->count());
    }

    public function test_update_rejects_edits_to_terminal_states_even_for_the_creator(): void
    {
        foreach (['completed', 'cancelled'] as $terminal) {
            $taskId = $this->seedTask(self::USER_A, $terminal);

            $response = $this->withToken($this->token)->patchJson('/api/v1/tasks/'.$taskId, ['title' => 'X'], [
                'X-Correlation-ID' => self::CORRELATION,
                'If-Match' => '"1"',
            ]);

            $response->assertStatus(409)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('type', 'https://cluster.example/problems/task-terminal-state');
            $this->assertSame('Seeded', DB::table('tasks')->where('id', $taskId)->value('title'));
        }
    }

    public function test_update_returns_404_for_unrelated_users_instead_of_revealing_existence(): void
    {
        $taskId = $this->seedTask(self::USER_A, 'open', self::USER_A);

        $tokenB = (string) $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-b',
            'password' => 'fixture-password-b',
        ], ['X-Correlation-ID' => self::CORRELATION])->assertOk()->json('data.access_token');

        // Strip B's role assignments so B is neither related to the task nor
        // a manager: the probe must answer 404 and never reveal the task id.
        DB::table('role_assignments')->where('user_id', self::USER_B)->delete();

        $response = $this->withToken($tokenB)->patchJson('/api/v1/tasks/'.$taskId, ['title' => 'X'], [
            'X-Correlation-ID' => self::CORRELATION,
            'If-Match' => '"1"',
        ]);

        $response->assertNotFound()
            ->assertJsonPath('type', 'https://cluster.example/problems/resource-not-found');
        $this->assertSame('Seeded', DB::table('tasks')->where('id', $taskId)->value('title'));
    }

    public function test_add_participant_and_add_comment_via_engagement(): void
    {
        $taskId = $this->seedTask(self::USER_A);

        $add = $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/participants', [
            'user_id' => self::USER_IN_SCOPE,
            'role' => 'reviewer',
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-part-'.Str::uuid7()->toString(),
            'If-Match' => '"1"',
        ]);
        $add->assertOk();

        $comment = $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/comments', [
            'body' => 'Hello',
            'mentioned_user_ids' => [self::USER_IN_SCOPE],
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-comment-'.Str::uuid7()->toString(),
        ]);
        $comment->assertStatus(201);

        $list = $this->withToken($this->token)->getJson('/api/v1/tasks/'.$taskId.'/comments', [
            'X-Correlation-ID' => self::CORRELATION,
        ]);
        $list->assertOk();
        $this->assertCount(1, $list->json('items'));
    }

    public function test_missing_correlation_id_yields_400(): void
    {
        $resp = $this->withToken($this->token)->getJson('/api/v1/tasks');
        $resp->assertStatus(400);
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
}

final class FailingReassignmentOutbox implements TransactionalOutbox
{
    public function append(string $eventId, string $aggregateId, string $eventType, array $payload): void
    {
        throw new \RuntimeException('task outbox unavailable');
    }
}

final class CountingUserDisplayLabels implements ListUserDisplayLabels
{
    public int $calls = 0;

    /** @var list<string> */
    public array $requestedIds = [];

    /** @param array<string, string> $labels */
    public function __construct(private readonly array $labels) {}

    /** @param list<string> $userIds @return array<string, string> */
    public function labelsFor(array $userIds): array
    {
        $this->calls++;
        $this->requestedIds = $userIds;

        return $this->labels;
    }
}

final class CountingOrganizationScopeLabels implements ListOrganizationScopeTargets
{
    public int $calls = 0;

    /** @var list<string> */
    public array $scopeTypes = [];

    public function __construct(private readonly bool $returnsLabels = true) {}

    /**
     * @param  'cluster'|'facility'|'unit'  $scopeType
     * @param  list<array{scope_type: 'cluster'|'facility'|'unit', scope_id: string}>  $candidates
     * @return array<int, array{scope_type: 'cluster'|'facility'|'unit', scope_id: string, label_ar: string, label_en: string, code?: string|null}>
     */
    public function labelCandidates(string $scopeType, array $candidates, ?string $search): array
    {
        $this->calls++;
        $this->scopeTypes[] = $scopeType;

        if (! $this->returnsLabels) {
            return [];
        }

        return array_map(static fn (array $candidate): array => [
            ...$candidate,
            'label_ar' => 'نطاق الاختبار',
            'label_en' => 'Test scope',
            'code' => 'TEST-SCOPE',
        ], $candidates);
    }
}
