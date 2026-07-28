<?php

declare(strict_types=1);

namespace Modules\Tasks\Tests;

use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
}
