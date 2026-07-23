<?php

namespace Modules\Workflow\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Workflow\Features\ListApprovalInbox\Query\ListApprovalInbox;
use Tests\TestCase;

final class ListApprovalInboxTest extends TestCase
{
    use RefreshDatabase;

    private const USER_A = '0197f0e0-0000-7000-8000-00000000000a';

    private const USER_B = '0197f0e0-0000-7000-8000-00000000000b';

    public function test_inbox_returns_only_steps_assigned_to_the_caller(): void
    {
        $this->seedStep(self::USER_A, 'active');
        $this->seedStep(self::USER_B, 'active');

        $query = new ListApprovalInbox;
        $result = $query->execute(self::USER_A, 'active', 50);

        $this->assertCount(1, $result['items']);
        $this->assertSame(self::USER_A, $result['items'][0]['assignee_user_id']);
        $this->assertNull($result['next_cursor']);
    }

    public function test_inbox_omits_completed_steps_when_state_filter_is_active(): void
    {
        $this->seedStep(self::USER_A, 'completed');
        $this->seedStep(self::USER_A, 'active');

        $result = (new ListApprovalInbox)->execute(self::USER_A, 'active', 50);

        $this->assertCount(1, $result['items']);
        $this->assertSame('active', $result['items'][0]['state']);
    }

    public function test_inbox_fails_closed_for_allowed_actions_without_an_authorization_callback(): void
    {
        $this->seedStep(self::USER_A, 'completed');
        $this->seedStep(self::USER_A, 'active');

        $result = (new ListApprovalInbox)->execute(self::USER_A, null, 50);

        $byState = [];
        foreach ($result['items'] as $item) {
            $byState[$item['state']] = $item['allowed_actions'];
        }

        $this->assertSame([], $byState['completed']);
        $this->assertSame([], $byState['active']);
    }

    public function test_inbox_uses_instance_source_and_stable_cursor_for_state_all(): void
    {
        $firstStepId = $this->seedStep(self::USER_A, 'active', 'leave_request', 'LR-1');
        $this->seedStep(self::USER_A, 'completed', 'leave_request', 'LR-2');

        $firstPage = (new ListApprovalInbox)->execute(self::USER_A, 'all', 1, null);

        $this->assertSame('leave_request', $firstPage['items'][0]['source_type']);
        $this->assertSame('LR-1', $firstPage['items'][0]['source_id']);
        $this->assertNotNull($firstPage['next_cursor']);

        $secondPage = (new ListApprovalInbox)->execute(self::USER_A, 'all', 1, $firstPage['next_cursor']);

        $this->assertCount(1, $secondPage['items']);
        $this->assertNotSame($firstStepId, $secondPage['items'][0]['step_id']);
        $this->assertNull($secondPage['next_cursor']);
    }

    public function test_inbox_projects_only_actions_authorized_for_the_step(): void
    {
        $this->seedStep(self::USER_A, 'active');

        $result = (new ListApprovalInbox)->execute(
            self::USER_A,
            'active',
            50,
            null,
            static fn (): array => ['approve'],
        );

        $this->assertSame(['approve'], $result['items'][0]['allowed_actions']);
    }

    public function test_filtered_inbox_cursor_skips_hidden_rows_without_skipping_later_visible_rows(): void
    {
        $hiddenStepId = $this->seedStep(self::USER_A, 'active');
        $firstVisibleStepId = $this->seedStep(self::USER_A, 'active');
        $secondVisibleStepId = $this->seedStep(self::USER_A, 'active');
        $visibilityCalls = 0;
        $visible = static function (array $rows) use (&$visibilityCalls, $firstVisibleStepId, $secondVisibleStepId): array {
            $visibilityCalls++;

            return [$firstVisibleStepId => true, $secondVisibleStepId => true];
        };

        $firstPage = (new ListApprovalInbox)->execute(self::USER_A, 'all', 1, null, null, $visible);
        $secondPage = (new ListApprovalInbox)->execute(self::USER_A, 'all', 1, $firstPage['next_cursor'], null, $visible);

        $this->assertNotSame($hiddenStepId, $firstPage['items'][0]['step_id']);
        $this->assertSame($firstVisibleStepId, $firstPage['items'][0]['step_id']);
        $this->assertSame($secondVisibleStepId, $secondPage['items'][0]['step_id']);
        $this->assertNull($secondPage['next_cursor']);
        $this->assertSame(2, $visibilityCalls, 'Visibility is resolved per fetched batch, never once per row.');
    }

    private function seedStep(string $assigneeUserId, string $state, string $sourceType = 'work_record', ?string $sourceId = null): string
    {
        $instanceId = (string) Str::uuid7();
        $stepId = (string) Str::uuid7();
        $now = Carbon::now();
        DB::table('workflow_instances')->insert([
            'id' => $instanceId,
            'workflow_version_id' => (string) Str::uuid7(),
            'source_module' => 'work_records',
            'source_type' => $sourceType,
            'source_id' => $sourceId ?? (string) Str::uuid7(),
            'state' => 'running',
            'started_by_user_id' => self::USER_A,
            'started_at' => $now,
            'completed_at' => null,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('workflow_step_instances')->insert([
            'id' => $stepId,
            'workflow_instance_id' => $instanceId,
            'node_key' => 'review-1',
            'node_type' => 'work_item',
            'state' => $state,
            'activation_sequence' => 1,
            'activated_at' => $now,
            'completed_at' => null,
            'task_id' => null,
            'assignee_user_id' => $assigneeUserId,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $stepId;
    }
}
