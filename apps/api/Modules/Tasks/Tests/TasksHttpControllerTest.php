<?php

namespace Modules\Tasks\Tests;

use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TasksHttpControllerTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION = '018f6f7d-0c00-7000-8000-000000000601';

    private string $token;

    private string $userId;

    private string $facilityId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DevelopmentJourneyAuthorizationSeeder::class);
        $this->token = $this->loginToken('fixture-account-a', 'fixture-password-a', self::CORRELATION);
        $this->userId = '018f6f7d-0c00-7000-8000-000000000021';
        $this->facilityId = '018f6f7d-0c00-7000-8000-000000000011';
    }

    private function loginToken(string $username, string $password, string $correlationId): string
    {
        $response = $this->postJson(
            '/api/v1/auth/login',
            ['username' => $username, 'password' => $password],
            ['X-Correlation-ID' => $correlationId]
        )->assertOk();

        return (string) $response->json('data.access_token');
    }

    private function seedTask(string $assignee, string $status = 'open'): string
    {
        $id = (string) Str::uuid7();
        DB::table('tasks')->insert([
            'id' => $id,
            'title' => 'Seeded',
            'description' => null,
            'created_by_user_id' => $this->userId,
            'assignee_user_id' => $assignee,
            'owner_organization_unit_id' => $this->facilityId,
            'status' => $status,
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
        $mine = $this->seedTask($this->userId);
        $other = $this->seedTask((string) Str::uuid7());

        $resp = $this->withToken($this->token)
            ->getJson('/api/v1/tasks', ['X-Correlation-ID' => self::CORRELATION]);

        $resp->assertOk();
        $ids = array_column($resp->json('items'), 'id');
        $this->assertContains($mine, $ids);
        $this->assertNotContains($other, $ids);
    }

    public function test_store_creates_task(): void
    {
        $resp = $this->withToken($this->token)
            ->postJson('/api/v1/tasks', [
                'title' => 'New task',
                'owner_organization_unit_id' => $this->facilityId,
                'assignee_user_id' => $this->userId,
                'priority' => 'high',
                'due_at' => '2026-12-31T23:59:59Z',
                'classification' => 'internal',
                'completion_policy' => 'direct',
            ], [
                'X-Correlation-ID' => self::CORRELATION,
                'Idempotency-Key' => 'idem-store-'.Str::uuid7()->toString(),
            ]);

        $resp->assertStatus(201);
        $this->assertSame('"1"', $resp->headers->get('ETag'));
    }

    public function test_store_rejects_invalid_payload(): void
    {
        $resp = $this->withToken($this->token)
            ->postJson('/api/v1/tasks', [
                'title' => '',
                'owner_organization_unit_id' => 'not-uuid',
                'assignee_user_id' => $this->userId,
                'priority' => 'urgent',
                'due_at' => 'tomorrow',
                'classification' => 'top-secret-x',
                'completion_policy' => 'maybe',
            ], [
                'X-Correlation-ID' => self::CORRELATION,
                'Idempotency-Key' => 'idem-bad-'.Str::uuid7()->toString(),
            ]);

        $resp->assertStatus(422);
    }

    public function test_show_returns_404_for_other_users_task(): void
    {
        $otherTask = $this->seedTask((string) Str::uuid7());

        $resp = $this->withToken($this->token)
            ->getJson('/api/v1/tasks/'.$otherTask, ['X-Correlation-ID' => self::CORRELATION]);

        $resp->assertNotFound();
    }

    public function test_update_requires_if_match_and_increments_lock_version(): void
    {
        $taskId = $this->seedTask($this->userId);

        $missing = $this->withToken($this->token)
            ->patchJson('/api/v1/tasks/'.$taskId, ['title' => 'X'], ['X-Correlation-ID' => self::CORRELATION]);
        $missing->assertStatus(412);

        $ok = $this->withToken($this->token)
            ->patchJson('/api/v1/tasks/'.$taskId, ['title' => 'Renamed'], [
                'X-Correlation-ID' => self::CORRELATION,
                'If-Match' => '"1"',
            ]);
        $ok->assertOk();
        $this->assertSame('Renamed', DB::table('tasks')->where('id', $taskId)->value('title'));
    }

    public function test_transition_start_and_complete(): void
    {
        $taskId = $this->seedTask($this->userId);

        $start = $this->withToken($this->token)
            ->postJson('/api/v1/tasks/'.$taskId.'/start', [], [
                'X-Correlation-ID' => self::CORRELATION,
                'Idempotency-Key' => 'idem-start-'.Str::uuid7()->toString(),
                'If-Match' => '"1"',
            ]);
        $start->assertOk();
        $this->assertSame('in_progress', DB::table('tasks')->where('id', $taskId)->value('status'));

        $complete = $this->withToken($this->token)
            ->postJson('/api/v1/tasks/'.$taskId.'/complete', [], [
                'X-Correlation-ID' => self::CORRELATION,
                'Idempotency-Key' => 'idem-complete-'.Str::uuid7()->toString(),
                'If-Match' => '"2"',
            ]);
        $complete->assertOk();
        $this->assertSame('completed', DB::table('tasks')->where('id', $taskId)->value('status'));
    }

    public function test_transition_rejects_unknown_action(): void
    {
        $taskId = $this->seedTask($this->userId);

        $resp = $this->withToken($this->token)
            ->postJson('/api/v1/tasks/'.$taskId.'/suspend', [], [
                'X-Correlation-ID' => self::CORRELATION,
                'Idempotency-Key' => 'idem-bad-'.Str::uuid7()->toString(),
                'If-Match' => '"1"',
            ]);

        $resp->assertStatus(404);
    }

    public function test_from_step_creates_task_linked_to_workflow_step(): void
    {
        $version = $this->app->make('Modules\\Workflow\\Features\\PublishWorkflowVersion\\Handler\\PublishWorkflowVersionHandler')->publish('task-flow', 'record', $this->userId, [
            'nodes' => [['key' => 'task', 'type' => 'task']],
        ]);
        $instance = $this->app->make('Modules\\Workflow\\Features\\StartWorkflow\\Handler\\StartWorkflowHandler')->start($version['id'], 'work_records', 'record', (string) Str::uuid7(), $this->userId);
        $step = DB::table('workflow_step_instances')->where('workflow_instance_id', $instance['id'])->first();

        $resp = $this->withToken($this->token)
            ->postJson('/api/v1/tasks/from-step/'.$step->id, ['title' => 'Step task'], [
                'X-Correlation-ID' => self::CORRELATION,
                'Idempotency-Key' => 'idem-fromstep-'.Str::uuid7()->toString(),
            ]);

        $resp->assertStatus(201);
        $this->assertSame('workflow_step', DB::table('tasks')->where('id', $resp->json('data.id'))->value('source_type'));
    }

    public function test_add_participant_list_comments(): void
    {
        $taskId = $this->seedTask($this->userId);
        $otherUserId = '018f6f7d-0c00-7000-8000-000000000022';

        $add = $this->withToken($this->token)
            ->postJson('/api/v1/tasks/'.$taskId.'/participants', [
                'user_id' => $otherUserId,
                'role' => 'reviewer',
            ], [
                'X-Correlation-ID' => self::CORRELATION,
                'Idempotency-Key' => 'idem-part-'.Str::uuid7()->toString(),
                'If-Match' => '"1"',
            ]);
        $add->assertOk();

        $comment = $this->withToken($this->token)
            ->postJson('/api/v1/tasks/'.$taskId.'/comments', [
                'body' => 'Hello',
                'mentioned_user_ids' => [$otherUserId],
            ], [
                'X-Correlation-ID' => self::CORRELATION,
                'Idempotency-Key' => 'idem-comment-'.Str::uuid7()->toString(),
            ]);
        $comment->assertStatus(201);

        $list = $this->withToken($this->token)
            ->getJson('/api/v1/tasks/'.$taskId.'/comments', ['X-Correlation-ID' => self::CORRELATION]);
        $list->assertOk();
        $this->assertCount(1, $list->json('items'));
    }

    public function test_missing_correlation_id_yields_400(): void
    {
        $resp = $this->withToken($this->token)->getJson('/api/v1/tasks');
        $resp->assertStatus(400);
    }
}
