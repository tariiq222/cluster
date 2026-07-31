<?php

declare(strict_types=1);

namespace Modules\Tasks\Tests;

use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            'user_id' => self::USER_B,
            'role' => 'reviewer',
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-part-'.Str::uuid7()->toString(),
            'If-Match' => '"1"',
        ]);
        $add->assertOk();

        $comment = $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/comments', [
            'body' => 'Hello',
            'mentioned_user_ids' => [self::USER_B],
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
}
