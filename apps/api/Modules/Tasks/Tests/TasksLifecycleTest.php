<?php

declare(strict_types=1);

namespace Modules\Tasks\Tests;

use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * End-to-end task lifecycle through the public /tasks routes. Each test
 * covers a single observable contract: HTTP status, ETag, and the resulting
 * task state. Idempotency, atomicity, and rejection details are pinned in
 * TasksCommandCoreTest; notifications are pinned in TasksNotificationsTest.
 */
final class TasksLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION = '018f6f7d-0c00-7000-8000-000000000601';

    private const USER_A = '018f6f7d-0c00-7000-8000-000000000021';

    private const FACILITY_A = '018f6f7d-0c00-7000-8000-000000000011';

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

    private function seedTask(string $assignee, string $state = 'open', int $lockVersion = 1, ?string $creator = null): string
    {
        $id = (string) Str::uuid7();
        DB::table('tasks')->insert([
            'id' => $id,
            'title' => 'Lifecycle task',
            'description' => null,
            'created_by_user_id' => $creator ?? self::USER_A,
            'assignee_user_id' => $assignee,
            'owner_organization_unit_id' => self::FACILITY_A,
            'status' => $state,
            'priority' => 'normal',
            'classification' => 'internal',
            'completion_policy' => 'direct',
            'lock_version' => $lockVersion,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function postTaskAction(string $taskId, string $action, int $version, array $body, ?string $idempotencyKey = null): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/'.$action, $body, [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => $idempotencyKey ?? 'idem-'.$action.'-'.Str::uuid7()->toString(),
            'If-Match' => '"'.$version.'"',
        ]);
    }

    public function test_open_to_in_progress_via_start(): void
    {
        $taskId = $this->seedTask(self::USER_A, 'open');
        $resp = $this->postTaskAction($taskId, 'start', 1, []);
        $resp->assertOk()->assertHeader('ETag', '"2"');
        $this->assertSame('in_progress', DB::table('tasks')->where('id', $taskId)->value('status'));
    }

    public function test_in_progress_to_blocked_requires_reason(): void
    {
        $taskId = $this->seedTask(self::USER_A, 'in_progress');

        $missing = $this->postTaskAction($taskId, 'block', 1, []);
        $missing->assertStatus(422)
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-task');

        $this->assertSame('in_progress', DB::table('tasks')->where('id', $taskId)->value('status'));

        $ok = $this->postTaskAction($taskId, 'block', 1, ['reason' => 'Need legal review']);
        $ok->assertOk();
        $this->assertSame('blocked', DB::table('tasks')->where('id', $taskId)->value('status'));
        $this->assertSame(
            'Block reason: Need legal review',
            (string) DB::table('task_comments')->where('task_id', $taskId)->value('body'),
        );
    }

    public function test_blocked_to_in_progress_via_unblock_by_assignee_or_creator(): void
    {
        $taskId = $this->seedTask(self::USER_A, 'blocked');

        $creator = $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/unblock', [], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-unblock-creator-'.Str::uuid7()->toString(),
            'If-Match' => '"1"',
        ]);
        $creator->assertOk();
        $this->assertSame('in_progress', DB::table('tasks')->where('id', $taskId)->value('status'));
    }

    public function test_in_progress_to_completed_requires_note(): void
    {
        $taskId = $this->seedTask(self::USER_A, 'in_progress');

        $missing = $this->postTaskAction($taskId, 'complete', 1, []);
        $missing->assertStatus(422)
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-task');

        $ok = $this->postTaskAction($taskId, 'complete', 1, ['note' => 'Done with full evidence']);
        $ok->assertOk();
        $this->assertSame('completed', DB::table('tasks')->where('id', $taskId)->value('status'));
        $this->assertNotNull(DB::table('tasks')->where('id', $taskId)->value('completed_at'));
    }

    public function test_cancel_from_open_requires_reason(): void
    {
        $taskId = $this->seedTask(self::USER_A, 'open');

        $missing = $this->postTaskAction($taskId, 'cancel', 1, []);
        $missing->assertStatus(422);

        $ok = $this->postTaskAction($taskId, 'cancel', 1, ['reason' => 'No longer relevant']);
        $ok->assertOk();
        $this->assertSame('cancelled', DB::table('tasks')->where('id', $taskId)->value('status'));
    }

    public function test_invalid_transition_returns_409(): void
    {
        $taskId = $this->seedTask(self::USER_A, 'open');

        $resp = $this->postTaskAction($taskId, 'complete', 1, ['note' => 'try shortcut']);
        $resp->assertStatus(409)
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-task-transition');
    }

    public function test_terminal_state_rejects_further_actions_with_409(): void
    {
        $taskId = $this->seedTask(self::USER_A, 'completed');

        $start = $this->postTaskAction($taskId, 'start', 1, []);
        $start->assertStatus(409);

        $cancel = $this->postTaskAction($taskId, 'cancel', 1, ['reason' => 'attempt']);
        $cancel->assertStatus(409);
    }

    public function test_unknown_action_returns_404_via_where_in(): void
    {
        $taskId = $this->seedTask(self::USER_A, 'open');

        $resp = $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/suspend', [], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-suspend-'.Str::uuid7()->toString(),
            'If-Match' => '"1"',
        ]);
        $resp->assertNotFound();
    }

    #[DataProvider('deniedTransitionProvider')]
    public function test_relationship_cannot_bypass_exact_transition_capability_denial(
        string $action,
        string $capability,
        string $state,
        array $body,
    ): void {
        $taskId = $this->seedTask(self::USER_A, $state);
        $this->bindRealAccessDecision();
        $this->denyJourneyCapability($capability);

        $response = $this->postTaskAction($taskId, $action, 1, $body);

        $response->assertForbidden();
        $this->assertSame($state, DB::table('tasks')->where('id', $taskId)->value('status'));
    }

    public static function deniedTransitionProvider(): array
    {
        return [
            'start requires tasks.start' => ['start', 'tasks.start', 'open', []],
            'block requires tasks.update' => ['block', 'tasks.update', 'in_progress', ['reason' => 'Denied']],
            'complete requires tasks.complete' => ['complete', 'tasks.complete', 'in_progress', ['note' => 'Denied']],
            'cancel requires tasks.cancel' => ['cancel', 'tasks.cancel', 'open', ['reason' => 'Denied']],
        ];
    }

    public function test_stale_if_match_returns_412(): void
    {
        $taskId = $this->seedTask(self::USER_A, 'open', 2);

        $resp = $this->postTaskAction($taskId, 'start', 1, []);
        $resp->assertStatus(412)
            ->assertJsonPath('type', 'https://cluster.example/problems/precondition-failed');

        $this->assertSame('open', DB::table('tasks')->where('id', $taskId)->value('status'));
        $this->assertSame(0, DB::table('outbox_events')->where('event_type', 'com.cluster.tasks.started.v1')->count());
    }

    public function test_idempotent_replay_returns_the_same_response_and_does_not_reapply(): void
    {
        $taskId = $this->seedTask(self::USER_A, 'open');
        $key = 'idem-replay-'.Str::uuid7()->toString();
        $headers = [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => $key,
            'If-Match' => '"1"',
        ];

        $first = $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/start', [], $headers);
        $replay = $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/start', [], $headers);

        $first->assertOk()->assertHeader('ETag', '"2"');
        $replay->assertOk()->assertHeader('ETag', '"2"');
        $this->assertSame($first->json(), $replay->json());

        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'com.cluster.tasks.started.v1')->count());
        $this->assertSame(1, DB::table('task_idempotency_keys')->count());
    }

    public function test_show_returns_allowed_actions_and_attachments_and_comments_summary(): void
    {
        $taskId = $this->seedTask(self::USER_A, 'open');
        DB::table('task_comments')->insert([
            'id' => (string) Str::uuid7(),
            'task_id' => $taskId,
            'author_user_id' => self::USER_A,
            'body' => 'Hi',
            'mentioned_user_ids' => json_encode([]),
            'created_at' => now(),
        ]);

        $resp = $this->withToken($this->token)->getJson('/api/v1/tasks/'.$taskId, [
            'X-Correlation-ID' => self::CORRELATION,
        ]);
        $resp->assertOk()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.state', 'open')
            ->assertJsonStructure([
                'data' => [
                    'id', 'title', 'state', 'classification', 'priority',
                    'assignee_user_id', 'creator_user_id', 'participant_user_ids',
                    'allowed_actions', 'attachments', 'comments_summary',
                    'lock_version', 'created_at', 'updated_at',
                ],
            ]);

        $allowed = $resp->json('data.allowed_actions');
        $this->assertContains('start', $allowed);
        $this->assertContains('edit', $allowed);
        $this->assertContains('reassign', $allowed);
        $this->assertContains('comment', $allowed);
        $this->assertContains('attach-document', $allowed);

        $this->assertSame(1, (int) $resp->json('data.comments_summary.count'));
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
