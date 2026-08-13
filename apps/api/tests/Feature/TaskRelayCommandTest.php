<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

final class TaskRelayCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_relay_requires_bounded_once_mode(): void
    {
        $this->assertSame(Command::FAILURE, Artisan::call('tasks:relay-events'));
        $this->assertStringContainsString('bounded --once mode is required', Artisan::output());
    }

    public function test_task_relay_runs_one_empty_bounded_cycle(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('tasks:relay-events', [
            '--once' => true,
            '--limit' => 999,
        ]));
        $this->assertStringContainsString('Relayed task events: 0', Artisan::output());
    }
}
