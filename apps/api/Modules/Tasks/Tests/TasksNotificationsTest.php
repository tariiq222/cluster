<?php

declare(strict_types=1);

namespace Modules\Tasks\Tests;

use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Notification recipients, actor exclusion, dedupe, and atomicity:
 * the in-transaction outbox + notifications row contract is observable
 * through the `notifications` table after each task mutation.
 */
final class TasksNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION = '018f6f7d-0c00-7000-8000-000000000603';

    private const USER_A = '018f6f7d-0c00-7000-8000-000000000021';

    private const PARTICIPANT = '018f6f7d-0c00-7000-8000-000000000041';

    private const FACILITY_A = '018f6f7d-0c00-7000-8000-000000000011';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DevelopmentJourneyAuthorizationSeeder::class);
        $this->token = (string) $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'fixture-password-a',
        ], ['X-Correlation-ID' => self::CORRELATION])->assertOk()->json('data.access_token');
    }

    private function seedTask(string $assignee, string $creator, string $state = 'open', array $extraParticipants = []): string
    {
        $id = (string) Str::uuid7();
        DB::table('tasks')->insert([
            'id' => $id,
            'title' => 'Notify task',
            'description' => null,
            'created_by_user_id' => $creator,
            'assignee_user_id' => $assignee,
            'owner_organization_unit_id' => self::FACILITY_A,
            'status' => $state,
            'priority' => 'normal',
            'classification' => 'internal',
            'completion_policy' => 'direct',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($extraParticipants as $participant) {
            DB::table('task_participants')->insert([
                'id' => (string) Str::uuid7(),
                'task_id' => $id,
                'user_id' => $participant,
                'role' => 'participant',
                'added_by_user_id' => $creator,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function postAction(string $taskId, string $action, int $version, array $body, ?string $key = null): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/'.$action, $body, [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => $key ?? 'idem-'.$action.'-'.Str::uuid7()->toString(),
            'If-Match' => '"'.$version.'"',
        ]);
    }

    public function test_self_task_creation_records_no_notification_for_actor(): void
    {
        $resp = $this->withToken($this->token)->postJson('/api/v1/tasks', [
            'title' => 'My own task',
            'priority' => 'normal',
            'classification' => 'internal',
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-self-'.Str::uuid7()->toString(),
        ]);
        $resp->assertStatus(201);

        // Actor excluded; for a self-task the recipient set collapses to nothing.
        $count = DB::table('notifications')->where('type', 'task.created')->count();
        $this->assertSame(0, $count);
    }

    public function test_creation_with_participants_notifies_participants_minus_actor(): void
    {
        $resp = $this->withToken($this->token)->postJson('/api/v1/tasks', [
            'title' => 'Notify participants',
            'assignee_user_id' => self::USER_A,
            'priority' => 'normal',
            'classification' => 'internal',
            'participant_user_ids' => [self::PARTICIPANT],
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-notify-'.Str::uuid7()->toString(),
        ]);
        $resp->assertStatus(201);

        $rows = DB::table('notifications')->where('type', 'task.created')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(self::PARTICIPANT, (string) $rows->first()->recipient_user_id);
    }

    public function test_transition_writes_notifications_to_participants_minus_actor(): void
    {
        $taskId = $this->seedTask(self::USER_A, self::USER_A, 'open', [self::PARTICIPANT]);

        $resp = $this->postAction($taskId, 'start', 1, []);
        $resp->assertOk();

        $rows = DB::table('notifications')->where('type', 'task.started')->get();
        $recipients = $rows->pluck('recipient_user_id')->all();
        $this->assertSame([self::PARTICIPANT], array_map('strval', $recipients));
    }

    public function test_add_comment_notifies_participants_minus_actor(): void
    {
        $taskId = $this->seedTask(self::USER_A, self::USER_A, 'open', [self::PARTICIPANT]);

        $resp = $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/comments', [
            'body' => 'Heads up',
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-c-'.Str::uuid7()->toString(),
        ]);
        $resp->assertStatus(201);

        $rows = DB::table('notifications')->where('type', 'task.commented')->get();
        $recipients = $rows->pluck('recipient_user_id')->all();
        $this->assertSame([self::PARTICIPANT], array_map('strval', $recipients));
    }

    public function test_failed_mutation_emits_no_notification_or_outbox_event(): void
    {
        $taskId = $this->seedTask(self::USER_A, self::USER_A, 'open');

        $stale = $this->postAction($taskId, 'start', 99, []);
        $stale->assertStatus(412);

        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame(0, DB::table('outbox_events')->where('event_type', 'task.start.v1')->count());
    }

    public function test_add_participant_emits_task_participant_added_notification(): void
    {
        $taskId = $this->seedTask(self::USER_A, self::USER_A, 'open');

        $resp = $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/participants', [
            'user_id' => self::PARTICIPANT,
            'role' => 'reviewer',
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-add-'.Str::uuid7()->toString(),
            'If-Match' => '"1"',
        ]);
        $resp->assertOk();

        $rows = DB::table('notifications')->where('type', 'task.participant_added')->get();
        $recipients = $rows->pluck('recipient_user_id')->all();
        $this->assertSame([self::PARTICIPANT], array_map('strval', $recipients));
    }
}
