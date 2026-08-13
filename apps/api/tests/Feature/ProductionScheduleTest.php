<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

final class ProductionScheduleTest extends TestCase
{
    public function test_required_bounded_maintenance_commands_are_scheduled(): void
    {
        $commands = array_map(
            static fn ($event): string => (string) $event->command,
            $this->app->make(Schedule::class)->events(),
        );

        foreach ([
            'platform-operations:dispatch --once --limit=10',
            'platform-settings:relay-technical-alerts --once --limit=100',
            'platform-settings:relay-events --once --limit=100',
            'notifications:consume-technical-alert --once --consumer=production-scheduler --limit=100',
            'notifications:replay-dlq --once --limit=100',
            'reporting:purge-expired --once --limit=100',
            'documents:expire-retention --once --limit=100',
        ] as $required) {
            $this->assertTrue(
                collect($commands)->contains(static fn (string $command): bool => str_contains($command, $required)),
                "Missing scheduled command: {$required}",
            );
        }
    }
}
