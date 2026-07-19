<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Day2HttpVerticalTest extends TestCase
{
    use RefreshDatabase;

    private const C = '018f6f7d-0c00-7000-8000-000000000201';

    public function test_definition_workflow_and_task_http_vertical_is_pinned_and_idempotent(): void
    {
        $headers = ['X-Correlation-ID' => self::C];
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'fixture-account-a', 'password' => 'fixture-password-a'], $headers)->assertOk()->json('data.access_token');
        $h = [...$headers, 'Idempotency-Key' => 'day2-definition'];
        $definition = $this->withToken($token)->postJson('/api/v1/work-definitions', ['code' => 'request_day2', 'name' => 'Request', 'source_record_type' => 'work_record'], $h)->assertCreated();
        $definition->assertHeader('ETag', '"1"');
        $id = $definition->json('data.id');
        $version = $this->withToken($token)->postJson('/api/v1/work-definitions/'.$id.'/versions', ['schema_document' => ['type' => 'object'], 'field_policy_key' => 'request'], [...$headers, 'Idempotency-Key' => 'day2-version'])->assertCreated();
        $versionId = $version->json('data.id');
        $published = $this->withToken($token)->postJson('/api/v1/work-definition-versions/'.$versionId.'/publish', [], [...$headers, 'Idempotency-Key' => 'day2-publish', 'If-Match' => '"1"'])->assertOk();
        $this->assertSame('published', $published->json('data.status'));
        $workflow = $this->withToken($token)->postJson('/api/v1/workflow/definitions', ['code' => 'day2_flow', 'name' => 'Day 2', 'source_record_type' => 'work_record'], [...$headers, 'Idempotency-Key' => 'day2-flow'])->assertCreated();
        $workflowVersion = $workflow->json('data.version.id');
        $this->withToken($token)->postJson('/api/v1/workflow/versions/'.$workflowVersion.'/publish', [], [...$headers, 'Idempotency-Key' => 'day2-flow-publish'])->assertOk();
        $instance = $this->withToken($token)->postJson('/api/v1/workflow/instances', ['workflow_version_id' => $workflowVersion, 'source_module' => 'work_records', 'record_type' => 'work_record', 'record_id' => '0197f0e0-0000-7000-8000-000000000099'], [...$headers, 'Idempotency-Key' => 'day2-start'])->assertCreated();
        $step = $this->app['db']->table('workflow_step_instances')->where('workflow_instance_id', $instance->json('data.id'))->first();
        $task = $this->withToken($token)->postJson('/api/v1/tasks/from-step/'.$step->id, [], [...$headers, 'Idempotency-Key' => 'day2-task'])->assertCreated();
        $completed = $this->withToken($token)->postJson('/api/v1/tasks/'.$task->json('data.id').'/complete', [], [...$headers, 'Idempotency-Key' => 'day2-complete', 'If-Match' => '"1"'])->assertOk();
        $this->assertSame('completed', $completed->json('data.status'));
    }
}
