<?php

declare(strict_types=1);

namespace Modules\Notifications\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\RecordFacts;
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
                $this->taskFacts($payload['task_id']),
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
            self::assertSame('facility-a', (string) $row->source_owner_facility_id);
            self::assertSame('confidential', (string) $row->source_classification);
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
                $service->record(['u1', 'u2'], 'task.created', $payload, $this->taskFacts($payload['task_id']));

                throw new \RuntimeException('abort outer transaction');
            });
        } catch (\RuntimeException) {
            // expected — the outer transaction must roll back.
        }

        self::assertSame(0, DB::table('notifications')->count());
    }

    public function test_record_resurfaces_a_read_task_notification_unread_on_a_same_group_event(): void
    {
        $service = $this->app->make(RecordTaskNotifications::class);
        $taskId = (string) Str::uuid7();
        $payload = [
            'task_id' => $taskId,
            'title' => 'Title',
            'actor_user_id' => (string) Str::uuid7(),
            'action' => 'updated',
        ];

        $service->record(['u1'], 'task.updated', $payload, $this->taskFacts($taskId));
        $notificationId = (string) DB::table('notifications')->value('id');
        $firstEventId = (string) DB::table('notifications')->value('event_id');
        DB::table('notifications')->where('id', $notificationId)->update([
            'is_read' => true,
            'status' => 'read',
        ]);

        $service->record(['u1'], 'task.updated', $payload, $this->taskFacts($taskId));

        $row = DB::table('notifications')->where('id', $notificationId)->first();
        self::assertNotNull($row);
        self::assertSame(1, DB::table('notifications')->count(), 'The same group key must aggregate, not duplicate.');
        self::assertSame(false, (bool) $row->is_read, 'A new same-group event must re-surface the notification as unread.');
        self::assertSame('unread', (string) $row->status);
        self::assertSame(2, (int) $row->aggregation_count);
        self::assertNotSame($firstEventId, (string) $row->last_event_id);
    }

    public function test_task_notification_fails_closed_when_authoritative_source_facts_are_absent(): void
    {
        $this->app->make(RecordTaskNotifications::class)->record(
            ['u1'],
            'task.created',
            [
                'task_id' => (string) Str::uuid7(),
                'title' => 'Must not leak',
                'actor_user_id' => (string) Str::uuid7(),
            ],
        );

        self::assertSame(0, DB::table('notifications')->count());
    }

    private function taskFacts(string $taskId): RecordFacts
    {
        return new RecordFacts(
            ownerFacilityId: 'facility-a',
            resourceType: 'task',
            classification: 'confidential',
            recordId: $taskId,
        );
    }
}
