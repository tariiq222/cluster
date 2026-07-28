<?php

declare(strict_types=1);

namespace Modules\Notifications\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Tasks\Contracts\RecordTaskNotifications;
use Tests\TestCase;

final class RecordTaskNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_writes_one_unread_row_per_unique_recipient_inside_caller_transaction(): void
    {
        $service = $this->app->make(RecordTaskNotifications::class);

        $payload = [
            'task_id' => (string) Str::uuid7(),
            'title' => 'Title',
            'actor_user_id' => (string) Str::uuid7(),
            'action' => 'created',
        ];

        $count = DB::transaction(function () use ($service, $payload): int {
            $service->record(
                ['u1', 'u2', 'u1'],
                'task.created',
                $payload,
            );

            return DB::table('notifications')->count();
        });

        self::assertSame(2, $count);
        $rows = DB::table('notifications')->get();
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertContains((string) $row->recipient_user_id, ['u1', 'u2']);
            self::assertSame('unread', (string) $row->status);
            self::assertSame(false, (bool) $row->is_read);
            self::assertSame('task.created', (string) $row->type);
            self::assertSame($payload, json_decode((string) $row->payload, true));
        }
    }

    public function test_record_opened_inside_an_outer_transaction_rolls_back_when_the_caller_aborts(): void
    {
        $service = $this->app->make(RecordTaskNotifications::class);

        $payload = [
            'task_id' => (string) Str::uuid7(),
            'title' => 'Title',
            'actor_user_id' => (string) Str::uuid7(),
            'action' => 'created',
        ];

        try {
            DB::transaction(function () use ($service, $payload): void {
                $service->record(['u1', 'u2'], 'task.created', $payload);

                throw new \RuntimeException('abort outer transaction');
            });
        } catch (\RuntimeException) {
            // expected — the outer transaction must roll back.
        }

        self::assertSame(0, DB::table('notifications')->count());
    }
}
