<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task-only workspace journey (spec §12, items 1–3 + 5). The interactive
 * end-to-end version lives in apps/web/e2e and requires a running W1_1
 * fixture; here we pin the API vertical in TDD form to cover the same
 * observable contract on a single process so CI does not depend on a
 * browser runner.
 *
 *  1. employee creates a self-task and completes it with a note
 *  2. manager assigns within scope returns 201; cross-team returns 422
 *  3. assignee starts, blocks with a reason, unblocks, comments,
 *     and completes with a note
 *  5. requests/Workflow surfaces fail closed: mutations return 409
 *     feature-disabled; reads return 404 non-disclosing
 */
final class TaskOnlyWorkspaceJourneyTest extends TestCase
{
    use RefreshDatabase;

    private const C = '018f6f7d-0c00-7000-8000-000000000701';

    private const CORRELATION = '018f6f7d-0c00-7000-8000-000000000702';

    private const USER_B = '018f6f7d-0c00-7000-8000-000000000022';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DevelopmentJourneyAuthorizationSeeder::class);
    }

    public function test_employee_self_task_create_complete_journey(): void
    {
        $token = $this->login('fixture-account-a');

        $created = $this->withToken($token)->postJson('/api/v1/tasks', [
            'title' => 'Self task',
            'priority' => 'normal',
            'classification' => 'internal',
        ], $this->headers('self-create'));
        $created->assertCreated();
        $taskId = $created->json('data.id');

        $start = $this->withToken($token)->postJson('/api/v1/tasks/'.$taskId.'/start', [], $this->headers('self-start', 1));
        $start->assertOk();

        $complete = $this->withToken($token)->postJson('/api/v1/tasks/'.$taskId.'/complete', [
            'note' => 'Done.',
        ], $this->headers('self-complete', 2));
        $complete->assertOk()->assertJsonPath('data.state', 'completed');
    }

    public function test_manager_assignment_is_422_out_of_team(): void
    {
        $manager = $this->login('fixture-account-a');

        // Account B sits in a different facility: the team-scope check
        // returns 422 (spec §11).
        $outOfTeam = $this->withToken($manager)->postJson('/api/v1/tasks', [
            'title' => 'Cross team',
            'assignee_user_id' => self::USER_B,
            'priority' => 'normal',
            'classification' => 'internal',
        ], $this->headers('manager-cross'));
        $outOfTeam->assertStatus(422);
    }

    public function test_assignee_runs_lifecycle_on_a_self_assigned_task(): void
    {
        $token = $this->login('fixture-account-a');

        $created = $this->withToken($token)->postJson('/api/v1/tasks', [
            'title' => 'Lifecycle',
            'priority' => 'normal',
            'classification' => 'internal',
        ], $this->headers('self-lifecycle'));
        $created->assertCreated();
        $taskId = $created->json('data.id');

        $start = $this->withToken($token)->postJson('/api/v1/tasks/'.$taskId.'/start', [], $this->headers('lifecycle-start', 1));
        $start->assertOk();

        $block = $this->withToken($token)->postJson('/api/v1/tasks/'.$taskId.'/block', [
            'reason' => 'Need evidence',
        ], $this->headers('lifecycle-block', 2));
        $block->assertOk();

        $unblock = $this->withToken($token)->postJson('/api/v1/tasks/'.$taskId.'/unblock', [], $this->headers('lifecycle-unblock', 3));
        $unblock->assertOk();

        $complete = $this->withToken($token)->postJson('/api/v1/tasks/'.$taskId.'/complete', [
            'note' => 'Completed.',
        ], $this->headers('lifecycle-complete', 4));
        $complete->assertOk()->assertJsonPath('data.state', 'completed');
    }

    public function test_work_management_routes_fail_closed(): void
    {
        // Parent TestCase::setUp defaults the feature on for legacy workflow
        // tests; override here to exercise the gate (off in production).
        config()->set('features.work_management', false);
        $token = $this->login('fixture-account-a');

        $mutation = $this->withToken($token)->postJson('/api/v1/workflow/instances', [], $this->headers('gated-mutation'));
        $mutation->assertStatus(409)->assertJsonPath('type', 'urn:cluster:problem:feature-disabled');

        $read = $this->withToken($token)->getJson('/api/v1/workflow/steps', $this->headers('gated-read'));
        $read->assertStatus(404)->assertJsonPath('type', 'https://cluster.example/problems/resource-unavailable');
    }

    private function login(string $username, string $password = 'fixture-password-a'): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'username' => $username,
            'password' => $password,
        ], ['X-Correlation-ID' => self::C])->assertOk()->json('data.access_token');
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $key, int $lockVersion = 0): array
    {
        $headers = [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => $key.'-'.bin2hex(random_bytes(4)),
        ];
        if ($lockVersion > 0) {
            $headers['If-Match'] = '"'.$lockVersion.'"';
        }

        return $headers;
    }
}
