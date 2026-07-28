<?php

declare(strict_types=1);

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

        $response = $this->withToken($token)->postJson('/api/v1/tasks', [
            'title' => 'Contract aligned task',
            'description' => 'Created through the documented task contract.',
            'owner_organization_unit_id' => $facilityId,
            'assignee_user_id' => $principalId,
            'priority' => 'high',
            'classification' => 'internal',
        ], [...$headers, 'Idempotency-Key' => 'task-contract-create'])->assertCreated();

        $taskId = $response->json('data.id');
        $response->assertJsonPath('data.title', 'Contract aligned task');
        $response->assertJsonPath('data.priority', 'high');
        $response->assertJsonPath('data.classification', 'internal');

        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'title' => 'Contract aligned task',
            'priority' => 'high',
            'classification' => 'internal',
        ]);

        $patched = $this->withToken($token)->patchJson('/api/v1/tasks/'.$taskId, [
            'title' => 'Patched task title',
        ], [...$headers, 'If-Match' => '"1"'])->assertOk();

        $patched->assertJsonPath('data.title', 'Patched task title');
        $this->assertSame('Patched task title', DB::table('tasks')->where('id', $taskId)->value('title'));
        $this->assertSame(2, (int) DB::table('tasks')->where('id', $taskId)->value('lock_version'));
    }
}
