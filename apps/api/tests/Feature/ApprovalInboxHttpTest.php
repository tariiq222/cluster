<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApprovalInboxHttpTest extends TestCase
{
    use RefreshDatabase;

    private const C = '018f6f7d-0c00-7000-8000-000000000401';

    private const APPROVER_B = '018f6f7d-0c00-7000-8000-000000000022';

    public function test_inbox_follows_the_step_assignee_and_never_leaks_another_principal(): void
    {
        $headers = ['X-Correlation-ID' => self::C];
        $tokenA = $this->login('fixture-account-a', 'fixture-password-a', $headers);
        $tokenB = $this->login('fixture-account-b', 'fixture-password-b', $headers);

        $workflow = $this->withToken($tokenA)->postJson('/api/v1/workflow/definitions', ['code' => 'inbox-flow', 'name' => 'Inbox', 'source_record_type' => 'work_record'], [...$headers, 'Idempotency-Key' => 'inbox-flow'])->assertCreated();
        $versionId = $workflow->json('data.version.id');
        $this->withToken($tokenB)->postJson('/api/v1/workflow/versions/'.$versionId.'/publish', [], [...$headers, 'Idempotency-Key' => 'inbox-publish'])->assertOk();
        $instance = $this->withToken($tokenA)->postJson('/api/v1/workflow/instances', ['workflow_version_id' => $versionId, 'source_module' => 'work_records', 'record_type' => 'work_record', 'record_id' => '018f6f7d-0c00-7000-8000-0000000005a1'], [...$headers, 'Idempotency-Key' => 'inbox-start'])->assertCreated();

        $step = $this->app['db']->table('workflow_step_instances')->where('workflow_instance_id', $instance->json('data.id'))->sole();

        $ownerInbox = $this->withToken($tokenA)->getJson('/api/v1/workflow/steps?assignee=me', $headers)->assertOk();
        $this->assertSame([$step->id], $ownerInbox->json('items.*.step_id'), 'The assignee must see their own waiting step.');
        $this->assertSame($instance->json('data.id'), $ownerInbox->json('items.0.workflow_instance_id'));

        $this->assertSame([], $this->withToken($tokenB)->getJson('/api/v1/workflow/steps?assignee=me', $headers)->assertOk()->json('items'), 'A principal must never see a step assigned to someone else.');

        $this->withToken($tokenA)->postJson('/api/v1/workflow/steps/'.$step->id.'/reassign', ['reason' => 'تحويل للمراجع الثاني', 'target_user_id' => self::APPROVER_B], [...$headers, 'Idempotency-Key' => 'inbox-reassign', 'If-Match' => '"1"'])->assertOk();

        $this->assertSame([$step->id], $this->withToken($tokenB)->getJson('/api/v1/workflow/steps?assignee=me', $headers)->assertOk()->json('items.*.step_id'), 'Reassignment must move the inbox entry.');
        $this->assertSame([], $this->withToken($tokenA)->getJson('/api/v1/workflow/steps?assignee=me', $headers)->assertOk()->json('items'), 'The previous approver must lose the entry.');
    }

    public function test_review_authority_reads_another_inbox_without_widening_it(): void
    {
        $headers = ['X-Correlation-ID' => self::C];
        $tokenA = $this->login('fixture-account-a', 'fixture-password-a', $headers);
        $tokenB = $this->login('fixture-account-b', 'fixture-password-b', $headers);

        $workflow = $this->withToken($tokenA)->postJson('/api/v1/workflow/definitions', ['code' => 'review-flow', 'name' => 'Review', 'source_record_type' => 'work_record'], [...$headers, 'Idempotency-Key' => 'review-flow'])->assertCreated();
        $versionId = $workflow->json('data.version.id');
        $this->withToken($tokenB)->postJson('/api/v1/workflow/versions/'.$versionId.'/publish', [], [...$headers, 'Idempotency-Key' => 'review-publish'])->assertOk();
        $instance = $this->withToken($tokenA)->postJson('/api/v1/workflow/instances', ['workflow_version_id' => $versionId, 'source_module' => 'work_records', 'record_type' => 'work_record', 'record_id' => '018f6f7d-0c00-7000-8000-0000000005b2'], [...$headers, 'Idempotency-Key' => 'review-start'])->assertCreated();
        $step = $this->app['db']->table('workflow_step_instances')->where('workflow_instance_id', $instance->json('data.id'))->sole();

        // The step belongs to A. A reviewer asking for B's inbox gets B's inbox, not their own.
        $this->assertSame(
            [],
            $this->withToken($tokenA)->getJson('/api/v1/workflow/steps?assignee_user_id='.self::APPROVER_B, $headers)->assertOk()->json('items'),
        );
        $this->assertSame(
            [$step->id],
            $this->withToken($tokenA)->getJson('/api/v1/workflow/steps?assignee=me', $headers)->assertOk()->json('items.*.step_id'),
        );
    }

    /** @param array<string, string> $headers */
    private function login(string $username, string $password, array $headers): string
    {
        return $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => $password], $headers)->assertOk()->json('data.access_token');
    }
}
