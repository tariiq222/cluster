<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WorkflowStepReassignHttpTest extends TestCase
{
    use RefreshDatabase;

    private const C = '018f6f7d-0c00-7000-8000-000000000301';

    private const OTHER_APPROVER = '018f6f7d-0c00-7000-8000-000000000022';

    public function test_reassigning_a_step_moves_the_approval_and_locks_out_the_previous_approver(): void
    {
        $headers = ['X-Correlation-ID' => self::C];
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'fixture-account-a', 'password' => 'fixture-password-a'], $headers)->assertOk()->json('data.access_token');

        $workflow = $this->withToken($token)->postJson('/api/v1/workflow/definitions', ['code' => 'reassign-flow', 'name' => 'Reassign', 'source_record_type' => 'work_record'], [...$headers, 'Idempotency-Key' => 'reassign-flow'])->assertCreated();
        $versionId = $workflow->json('data.version.id');
        $this->withToken($token)->postJson('/api/v1/workflow/versions/'.$versionId.'/publish', [], [...$headers, 'Idempotency-Key' => 'reassign-publish'])->assertOk();
        $instance = $this->withToken($token)->postJson('/api/v1/workflow/instances', ['workflow_version_id' => $versionId, 'source_module' => 'work_records', 'record_type' => 'work_record', 'record_id' => '018f6f7d-0c00-7000-8000-0000000004a1'], [...$headers, 'Idempotency-Key' => 'reassign-start'])->assertCreated();

        $step = $this->step($instance->json('data.id'));
        $this->assertNotNull($step->assignee_user_id, 'A started step must name its approver.');
        $this->assertNotSame(self::OTHER_APPROVER, $step->assignee_user_id);

        $this->withToken($token)->postJson('/api/v1/workflow/steps/'.$step->id.'/reassign', ['reason' => 'المدير في إجازة', 'target_user_id' => self::OTHER_APPROVER], [...$headers, 'Idempotency-Key' => 'reassign-step', 'If-Match' => '"1"'])->assertOk();

        $reassigned = $this->step($instance->json('data.id'));
        $this->assertSame(self::OTHER_APPROVER, $reassigned->assignee_user_id, 'Reassignment must move the step itself, not a task row.');
        $this->assertSame(2, (int) $reassigned->lock_version);

        $this->withToken($token)->postJson('/api/v1/workflow/steps/'.$step->id.'/decisions', ['decision' => 'approve'], [...$headers, 'Idempotency-Key' => 'reassign-decide', 'If-Match' => '"2"'])->assertForbidden();
        $this->assertSame('waiting', $this->step($instance->json('data.id'))->state);
    }

    private function step(string $instanceId): object
    {
        return $this->app['db']->table('workflow_step_instances')->where('workflow_instance_id', $instanceId)->sole();
    }
}
