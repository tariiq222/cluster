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
        $approverToken = $this->postJson('/api/v1/auth/login', ['username' => 'fixture-account-b', 'password' => 'fixture-password-b'], $headers)->assertOk()->json('data.access_token');
        $h = [...$headers, 'Idempotency-Key' => 'day2-definition'];
        $definition = $this->withToken($token)->postJson('/api/v1/work-definitions', ['code' => 'request-day2', 'name' => 'Request', 'default_classification' => 'internal'], $h)->assertCreated();
        $definition->assertHeader('ETag', '"1"');
        $id = $definition->json('data.id');
        $version = $this->withToken($token)->postJson('/api/v1/work-definitions/'.$id.'/versions', ['schema_document' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'description' => ['type' => 'string']]], 'field_policy_key' => 'request'], [...$headers, 'Idempotency-Key' => 'day2-version'])->assertCreated();
        $versionId = $version->json('data.id');
        $published = $this->withToken($token)->postJson('/api/v1/work-definition-versions/'.$versionId.'/publish', [], [...$headers, 'Idempotency-Key' => 'day2-publish', 'If-Match' => '"1"'])->assertOk();
        $this->assertSame('published', $published->json('data.status'));
        $workflow = $this->withToken($token)->postJson('/api/v1/workflow/definitions', ['code' => 'day2-flow', 'name' => 'Day 2', 'source_record_type' => 'work_record'], [...$headers, 'Idempotency-Key' => 'day2-flow'])->assertCreated();
        $workflowDefinitionId = $workflow->json('data.definition.id');
        $workflowVersion = $workflow->json('data.version.id');
        $this->withToken($token)->postJson('/api/v1/workflow/versions/'.$workflowVersion.'/publish', [], [...$headers, 'Idempotency-Key' => 'day2-flow-publish'])->assertOk();
        $record = $this->withToken($token)->postJson('/api/v1/work-records', ['work_definition_code' => 'request-day2', 'title' => 'Day 2 record', 'description' => 'HTTP journey'], [...$headers, 'Idempotency-Key' => 'day2-record'])->assertCreated();
        $recordId = $record->json('data.id');
        $this->assertSame($versionId, $record->json('data.work_type_version_id'));
        $this->withToken($token)->postJson('/api/v1/work-records/'.$recordId.'/submit', [], [...$headers, 'Idempotency-Key' => 'day2-record-submit', 'If-Match' => '"1"'])->assertOk();
        $instance = $this->withToken($token)->postJson('/api/v1/workflow/instances', ['workflow_version_id' => $workflowVersion, 'source_module' => 'work_records', 'record_type' => 'work_record', 'record_id' => $recordId], [...$headers, 'Idempotency-Key' => 'day2-start'])->assertCreated();
        $this->assertSame($workflowVersion, $instance->json('data.workflow_version_id'));
        $v2 = $this->withToken($token)->postJson('/api/v1/workflow/definitions/'.$workflowDefinitionId.'/versions', ['nodes' => [['key' => 'start', 'type' => 'start'], ['key' => 'task2', 'type' => 'task'], ['key' => 'end', 'type' => 'end']], 'transitions' => [['from' => 'start', 'to' => 'task2'], ['from' => 'task2', 'to' => 'end']], 'decision_policy' => ['default' => 'owner']], [...$headers, 'Idempotency-Key' => 'day2-flow-v2'])->assertCreated();
        $this->withToken($approverToken)->postJson('/api/v1/workflow/versions/'.$v2->json('data.id').'/publish', [], [...$headers, 'Idempotency-Key' => 'day2-flow-v2-publish'])->assertOk();
        $v2WorkDefinition = $this->withToken($token)->postJson('/api/v1/work-definitions/'.$id.'/versions', ['schema_document' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'description' => ['type' => 'string'], 'priority' => ['type' => 'string']]], 'field_policy_key' => 'request-v2'], [...$headers, 'Idempotency-Key' => 'day2-version-v2'])->assertCreated();
        $v2WorkDefinitionId = $v2WorkDefinition->json('data.id');
        $this->withToken($token)->postJson('/api/v1/work-definition-versions/'.$v2WorkDefinitionId.'/publish', [], [...$headers, 'Idempotency-Key' => 'day2-publish-v2', 'If-Match' => '"1"'])->assertOk();
        $newRecord = $this->withToken($token)->postJson('/api/v1/work-records', ['work_definition_code' => 'request-day2', 'title' => 'Day 2 v2 record', 'description' => 'HTTP journey v2'], [...$headers, 'Idempotency-Key' => 'day2-record-v2'])->assertCreated();
        $this->assertSame($v2WorkDefinitionId, $newRecord->json('data.work_type_version_id'));
        $this->assertSame($versionId, $this->withToken($token)->getJson('/api/v1/work-records/'.$recordId, $headers)->assertOk()->json('data.work_type_version_id'));
        $step = $this->app['db']->table('workflow_step_instances')->where('workflow_instance_id', $instance->json('data.id'))->first();
        $task = $this->withToken($token)->postJson('/api/v1/tasks/from-step/'.$step->id, [], [...$headers, 'Idempotency-Key' => 'day2-task'])->assertCreated();
        $replayTask = $this->withToken($token)->postJson('/api/v1/tasks/from-step/'.$step->id, [], [...$headers, 'Idempotency-Key' => 'day2-task'])->assertCreated();
        $this->assertSame($task->json('data.id'), $replayTask->json('data.id'));
        $this->withToken($token)->getJson('/api/v1/tasks/'.$task->json('data.id'), $headers)
            ->assertOk()
            ->assertJsonPath('data.id', $task->json('data.id'));
        $this->withToken($token)->postJson('/api/v1/tasks/'.$task->json('data.id').'/return', [], [...$headers, 'Idempotency-Key' => 'day2-return-task', 'If-Match' => '"1"'])->assertOk();
        $completed = $this->withToken($token)->postJson('/api/v1/tasks/'.$task->json('data.id').'/complete', [], [...$headers, 'Idempotency-Key' => 'day2-complete', 'If-Match' => '"2"'])->assertOk();
        $this->assertSame('completed', $completed->json('data.status'));
        $this->withToken($token)->postJson('/api/v1/work-records/'.$recordId.'/return', [], [...$headers, 'Idempotency-Key' => 'day2-return-record', 'If-Match' => '"2"'])->assertOk();
        $recordCompleted = $this->withToken($token)->postJson('/api/v1/work-records/'.$recordId.'/complete', [], [...$headers, 'Idempotency-Key' => 'day2-complete-record', 'If-Match' => '"3"'])->assertOk();
        $this->assertSame('completed', $recordCompleted->json('data.status'));
        $this->withToken($token)->getJson('/api/v1/work-records?limit=20', $headers)
            ->assertOk()
            ->assertJsonPath('items.0.id', $recordId);
        $this->assertSame(1, $this->app['db']->table('tasks')->count());
        $this->assertSame(1, $this->app['db']->table('outbox_events')->where('event_type', 'task.created.v1')->count());
        $this->assertSame(1, $this->app['db']->table('outbox_events')->where('event_type', 'task.completed.v1')->count());
        $this->assertSame(1, $this->app['db']->table('outbox_events')->where('event_type', 'work_record.return.v1')->count());
        $this->assertSame(1, $this->app['db']->table('outbox_events')->where('event_type', 'work_record.complete.v1')->count());
        $this->assertSame($task->json('data.id'), $this->app['db']->table('outbox_events')->where('event_type', 'task.created.v1')->value('aggregate_id'));
        $this->assertSame($recordId, $this->app['db']->table('outbox_events')->where('event_type', 'work_record.complete.v1')->value('aggregate_id'));
    }
}
