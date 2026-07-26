<?php

namespace Modules\Workflow\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Workflow\Features\PublishWorkflowVersion\Handler\PublishWorkflowVersionHandler;
use Modules\Workflow\Features\StartWorkflow\Handler\StartWorkflowHandler;
use RuntimeException;
use Shared\Contracts\TransactionalOutbox;
use Tests\TestCase;

final class WorkflowCoreTest extends TestCase
{
    use RefreshDatabase;

    private const USER = '0197f0e0-0000-7000-8000-000000000001';

    public function test_instance_remains_pinned_to_v1_after_v2_is_published(): void
    {
        $publisher = $this->app->make(PublishWorkflowVersionHandler::class);
        $v1 = $publisher->publish('request-approval', 'work_record', self::USER, $this->graph('first'));
        $instance = $this->app->make(StartWorkflowHandler::class)->start($v1['id'], 'work_records', 'record', '0197f0e0-0000-7000-8000-000000000099', self::USER);
        $v2 = $publisher->publish('request-approval', 'work_record', self::USER, $this->graph('second'));

        $this->assertSame($v1['id'], $instance['workflow_version_id']);
        $this->assertNotSame($v1['id'], $v2['id']);
        $this->assertSame(1, $instance['lock_version']);
        $this->assertSame(2, $v2['version_number']);
    }

    public function test_outbox_failure_rolls_back_version_and_definition(): void
    {
        $beforeDefinitions = DB::table('workflow_definitions')->count();
        $beforeVersions = DB::table('workflow_versions')->count();
        $beforeOutbox = DB::table('outbox_events')->count();
        $this->app->instance(TransactionalOutbox::class, new class implements TransactionalOutbox
        {
            public function append(string $eventId, string $aggregateId, string $eventType, array $payload): void
            {
                throw new RuntimeException('outbox unavailable');
            }
        });

        try {
            $this->app->make(PublishWorkflowVersionHandler::class)->publish('rollback', 'record', self::USER, $this->graph('x'));
            $this->fail('The failing outbox should abort workflow version publication.');
        } catch (RuntimeException $exception) {
            $this->assertSame('outbox unavailable', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('workflow_definitions')->where('code', 'rollback')->count());
        $this->assertSame($beforeDefinitions, DB::table('workflow_definitions')->count());
        $this->assertSame($beforeVersions, DB::table('workflow_versions')->count());
        $this->assertSame($beforeOutbox, DB::table('outbox_events')->count());
    }

    /** @return array<string, mixed> */
    private function graph(string $title): array
    {
        return ['nodes' => [
            ['key' => 'start', 'type' => 'start'],
            ['key' => 'review', 'type' => 'work_item', 'configuration' => ['title' => $title]],
            ['key' => 'end', 'type' => 'end'],
        ], 'transitions' => [['from' => 'start', 'to' => 'review'], ['from' => 'review', 'to' => 'end']]];
    }
}
