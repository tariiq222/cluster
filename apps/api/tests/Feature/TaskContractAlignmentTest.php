<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TaskContractAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private const C = '018f6f7d-0c00-7000-8000-000000000201';

    public function test_task_create_and_patch_match_the_openapi_contract(): void
    {
        $headers = ['X-Correlation-ID' => self::C];
        $login = $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'fixture-password-a',
        ], $headers)->assertOk();
        $token = $login->json('data.access_token');
        $principalId = $login->json('data.principal.user_id');
        $facilityId = $login->json('data.principal.facility_id');

        $dueAt = '2026-01-15T10:30:00Z';
        $nextDueAt = '2026-01-20T09:15:00Z';
        $response = $this->withToken($token)->postJson('/api/v1/tasks', [
            'title' => 'Contract aligned task',
            'description' => 'Created through the documented task contract.',
            'owner_organization_unit_id' => $facilityId,
            'assignee_user_id' => $principalId,
            'priority' => 'high',
            'due_at' => $dueAt,
            'classification' => 'internal',
            'source' => [
                'source_module' => 'workflow',
                'record_type' => 'workflow_step',
                'record_id' => '0197f0e0-0000-7000-8000-000000000091',
            ],
            'completion_policy' => 'requires_acceptance',
        ], [...$headers, 'Idempotency-Key' => 'task-contract-create'])->assertCreated();

        $taskId = $response->json('data.id');
        $response->assertJsonPath('data.title', 'Contract aligned task');
        $response->assertJsonPath('data.due_at', $dueAt);
        $response->assertJsonPath('data.priority', 'high');
        $response->assertJsonPath('data.classification', 'internal');
        $response->assertJsonPath('data.completion_policy', 'requires_acceptance');

        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'title' => 'Contract aligned task',
            'priority' => 'high',
            'classification' => 'internal',
            'completion_policy' => 'requires_acceptance',
            'source_module' => 'workflow',
            'source_type' => 'workflow_step',
            'source_id' => '0197f0e0-0000-7000-8000-000000000091',
        ]);

        $patched = $this->withToken($token)->patchJson('/api/v1/tasks/'.$taskId, [
            'title' => 'Patched task title',
            'due_at' => $nextDueAt,
        ], [...$headers, 'If-Match' => '"1"'])->assertOk();

        $patched->assertJsonPath('data.title', 'Patched task title');
        $patched->assertJsonPath('data.due_at', $nextDueAt);
        $this->assertSame('Patched task title', DB::table('tasks')->where('id', $taskId)->value('title'));
        $this->assertSame($nextDueAt, DB::table('tasks')->where('id', $taskId)->value('due_at'));
        $this->assertSame(2, (int) DB::table('tasks')->where('id', $taskId)->value('lock_version'));
    }
}
