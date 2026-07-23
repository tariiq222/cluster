<?php

namespace Modules\Workflow\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->app->instance(TransactionalOutbox::class, new class implements TransactionalOutbox
        {
            public function append(string $eventId, string $aggregateId, string $eventType, array $payload): void
            {
                throw new RuntimeException('outbox unavailable');
            }
        });

        $this->expectException(RuntimeException::class);
        $this->app->make(PublishWorkflowVersionHandler::class)->publish('rollback', 'record', self::USER, $this->graph('x'));
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
