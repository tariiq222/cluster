<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the observable outcome of each step decision on the single-step
 * published vertical: the step transition, the decisions ledger row, and
 * what happens to the owning workflow instance.
 *
 * NOTE: reject/return currently leave the instance `running` — no terminal
 * transition exists for a rejected or returned step. These tests pin that
 * current contract so the day a rejection policy lands the diff is explicit.
 */
final class WorkflowDecisionHttpTest extends TestCase
{
    use RefreshDatabase;

    private const C = '018f6f7d-0c00-7000-8000-000000000302';

    public function test_approve_completes_the_step_and_closes_the_instance(): void
    {
        [$token, $headers, $instanceId, $step] = $this->runningInstance('decide-approve');

        $this->withToken($token)->postJson('/api/v1/workflow/steps/'.$step->id.'/decisions', ['decision' => 'approve'], [...$headers, 'Idempotency-Key' => 'decide-approve', 'If-Match' => '"1"'])->assertCreated();

        $after = $this->step($instanceId);
        $this->assertSame('completed', $after->state);
        $this->assertSame(2, (int) $after->lock_version);
        $this->assertNotNull($after->completed_at);
        $this->assertDatabaseHas('workflow_decisions', ['workflow_step_id' => $step->id, 'decision' => 'approve']);
        $instance = $this->workflowInstance($instanceId);
        $this->assertSame('completed', $instance->state, 'With no other open steps the instance must close.');
        $this->assertSame(2, (int) $instance->lock_version);
    }

    public function test_reject_marks_the_step_rejected_and_leaves_the_instance_running(): void
    {
        [$token, $headers, $instanceId, $step] = $this->runningInstance('decide-reject');

        $this->withToken($token)->postJson('/api/v1/workflow/steps/'.$step->id.'/decisions', ['decision' => 'reject', 'reason' => 'الطلب ناقص'], [...$headers, 'Idempotency-Key' => 'decide-reject', 'If-Match' => '"1"'])->assertCreated();

        $this->assertSame('rejected', $this->step($instanceId)->state);
        $this->assertDatabaseHas('workflow_decisions', ['workflow_step_id' => $step->id, 'decision' => 'reject', 'reason' => 'الطلب ناقص']);
        $this->assertSame('running', $this->workflowInstance($instanceId)->state, 'Current contract: rejection has no instance-level terminal transition.');
    }

    public function test_return_marks_the_step_returned_and_leaves_the_instance_running(): void
    {
        [$token, $headers, $instanceId, $step] = $this->runningInstance('decide-return');

        $this->withToken($token)->postJson('/api/v1/workflow/steps/'.$step->id.'/decisions', ['decision' => 'return', 'reason' => 'أكمل البيانات'], [...$headers, 'Idempotency-Key' => 'decide-return', 'If-Match' => '"1"'])->assertCreated();

        $this->assertSame('returned', $this->step($instanceId)->state);
        $this->assertDatabaseHas('workflow_decisions', ['workflow_step_id' => $step->id, 'decision' => 'return', 'reason' => 'أكمل البيانات']);
        $this->assertSame('running', $this->workflowInstance($instanceId)->state, 'Current contract: return has no instance-level terminal transition.');
    }

    /** @return array{0: string, 1: array<string, string>, 2: string, 3: object} */
    private function runningInstance(string $keyPrefix): array
    {
        $headers = ['X-Correlation-ID' => self::C];
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'fixture-account-a', 'password' => 'fixture-password-a'], $headers)->assertOk()->json('data.access_token');

        $workflow = $this->withToken($token)->postJson('/api/v1/workflow/definitions', ['code' => $keyPrefix.'-flow', 'name' => 'Decide', 'source_record_type' => 'work_record'], [...$headers, 'Idempotency-Key' => $keyPrefix.'-flow'])->assertCreated();
        $versionId = $workflow->json('data.version.id');
        $this->withToken($token)->postJson('/api/v1/workflow/versions/'.$versionId.'/publish', [], [...$headers, 'Idempotency-Key' => $keyPrefix.'-publish'])->assertOk();
        $instance = $this->withToken($token)->postJson('/api/v1/workflow/instances', ['workflow_version_id' => $versionId, 'source_module' => 'work_records', 'record_type' => 'work_record', 'record_id' => '018f6f7d-0c00-7000-8000-0000000004a2'], [...$headers, 'Idempotency-Key' => $keyPrefix.'-start'])->assertCreated();

        $instanceId = $instance->json('data.id');

        return [$token, $headers, $instanceId, $this->step($instanceId)];
    }

    private function step(string $instanceId): object
    {
        return $this->app['db']->table('workflow_step_instances')->where('workflow_instance_id', $instanceId)->sole();
    }

    private function workflowInstance(string $instanceId): object
    {
        return $this->app['db']->table('workflow_instances')->where('id', $instanceId)->sole();
    }
}
