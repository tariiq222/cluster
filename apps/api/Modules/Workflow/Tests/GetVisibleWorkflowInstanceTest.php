<?php

namespace Modules\Workflow\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Workflow\Features\GetVisibleWorkflowInstance\Query\GetVisibleWorkflowInstance;
use Tests\TestCase;

final class GetVisibleWorkflowInstanceTest extends TestCase
{
    use RefreshDatabase;

    private const USER_A = '0197f0e0-0000-7000-8000-00000000000a';

    private const USER_B = '0197f0e0-0000-7000-8000-00000000000b';

    public function test_returns_payload_when_caller_started_the_instance(): void
    {
        $instanceId = $this->seedInstance(self::USER_A, self::USER_A);

        $result = (new GetVisibleWorkflowInstance)->fetch($instanceId, self::USER_A);

        $this->assertNotNull($result);
        $this->assertSame($instanceId, $result['id']);
        $this->assertCount(1, $result['step_history']);
    }

    public function test_returns_payload_when_caller_is_assigned_to_a_step(): void
    {
        $instanceId = $this->seedInstance(self::USER_A, self::USER_B);

        $result = (new GetVisibleWorkflowInstance)->fetch($instanceId, self::USER_B);

        $this->assertNotNull($result);
        $this->assertSame($instanceId, $result['id']);
    }

    public function test_assignee_sees_only_steps_assigned_to_them(): void
    {
        $instanceId = $this->seedInstance(self::USER_A, self::USER_B);
        $this->seedAdditionalStep($instanceId, self::USER_A);

        $result = (new GetVisibleWorkflowInstance)->fetch($instanceId, self::USER_B);

        $this->assertNotNull($result);
        $this->assertSame([self::USER_B], array_column($result['step_history'], 'assignee_user_id'));
    }

    public function test_returns_null_when_caller_has_no_relation_to_the_instance(): void
    {
        $instanceId = $this->seedInstance(self::USER_A, self::USER_A);

        $result = (new GetVisibleWorkflowInstance)->fetch($instanceId, self::USER_B);

        $this->assertNull($result);
    }

    public function test_returns_null_when_instance_does_not_exist(): void
    {
        $result = (new GetVisibleWorkflowInstance)->fetch((string) Str::uuid7(), self::USER_A);
        $this->assertNull($result);
    }

    private function seedInstance(string $startedBy, string $assignee): string
    {
        $instanceId = (string) Str::uuid7();
        $now = Carbon::now();
        DB::table('workflow_instances')->insert([
            'id' => $instanceId,
            'workflow_version_id' => (string) Str::uuid7(),
            'source_module' => 'work_records',
            'source_type' => 'work_record',
            'source_id' => (string) Str::uuid7(),
            'state' => 'running',
            'started_by_user_id' => $startedBy,
            'started_at' => $now,
            'completed_at' => null,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('workflow_step_instances')->insert([
            'id' => (string) Str::uuid7(),
            'workflow_instance_id' => $instanceId,
            'node_key' => 'review-1',
            'node_type' => 'work_item',
            'state' => 'active',
            'activation_sequence' => 1,
            'activated_at' => $now,
            'completed_at' => null,
            'task_id' => null,
            'assignee_user_id' => $assignee,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $instanceId;
    }

    private function seedAdditionalStep(string $instanceId, string $assignee): void
    {
        $now = Carbon::now();
        DB::table('workflow_step_instances')->insert([
            'id' => (string) Str::uuid7(),
            'workflow_instance_id' => $instanceId,
            'node_key' => 'review-2',
            'node_type' => 'work_item',
            'state' => 'active',
            'activation_sequence' => 1,
            'activated_at' => $now,
            'completed_at' => null,
            'task_id' => null,
            'assignee_user_id' => $assignee,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
