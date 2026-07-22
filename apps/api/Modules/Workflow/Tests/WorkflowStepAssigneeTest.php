<?php

namespace Modules\Workflow\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Workflow\Features\PublishWorkflowVersion\Handler\PublishWorkflowVersionHandler;
use Modules\Workflow\Features\StartWorkflow\Handler\StartWorkflowHandler;
use Tests\TestCase;

final class WorkflowStepAssigneeTest extends TestCase
{
    use RefreshDatabase;

    private const STARTER = '0197f0e0-0000-7000-8000-000000000001';

    private const APPROVER = '0197f0e0-0000-7000-8000-000000000002';

    public function test_step_is_owned_by_the_starter_when_the_graph_names_nobody(): void
    {
        $step = $this->startAndFetchStep($this->graph());

        $this->assertSame(self::STARTER, $step->assignee_user_id);
    }

    public function test_step_is_owned_by_the_approver_the_graph_names(): void
    {
        $step = $this->startAndFetchStep($this->graph(self::APPROVER));

        $this->assertSame(self::APPROVER, $step->assignee_user_id);
    }

    public function test_step_reads_the_approver_from_the_node_configuration(): void
    {
        $graph = $this->graph();
        $graph['nodes'][1]['configuration'] = ['assignee_user_id' => self::APPROVER];

        $this->assertSame(self::APPROVER, $this->startAndFetchStep($graph)->assignee_user_id);
    }

    /** @param array<string, mixed> $graph */
    private function startAndFetchStep(array $graph): object
    {
        $version = $this->app->make(PublishWorkflowVersionHandler::class)
            ->publish('assignee-'.bin2hex(random_bytes(4)), 'work_record', self::STARTER, $graph);
        $instance = $this->app->make(StartWorkflowHandler::class)
            ->start($version['id'], 'work_records', 'record', '0197f0e0-0000-7000-8000-000000000099', self::STARTER);

        return DB::table('workflow_step_instances')->where('workflow_instance_id', $instance['id'])->sole();
    }

    /** @return array<string, mixed> */
    private function graph(?string $assigneeUserId = null): array
    {
        $task = ['key' => 'approval', 'type' => 'task'];
        if ($assigneeUserId !== null) {
            $task['assignee_user_id'] = $assigneeUserId;
        }

        return [
            'nodes' => [['key' => 'start', 'type' => 'start'], $task, ['key' => 'end', 'type' => 'end']],
            'transitions' => [['from' => 'start', 'to' => 'approval'], ['from' => 'approval', 'to' => 'end']],
        ];
    }
}
