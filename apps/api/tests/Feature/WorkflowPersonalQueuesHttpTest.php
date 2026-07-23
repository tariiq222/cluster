<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Tests\TestCase;

/**
 * HTTP-level coverage of the personal queue contracts added for the dashboard
 * redesign. The fixture-login flow lets us drive two principals through the
 * real Laravel routes and middleware without bespoke auth overrides.
 */
final class WorkflowPersonalQueuesHttpTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_A = '018f6f7d-0c00-7000-8000-000000000501';

    public function test_inbox_for_self_returns_only_own_steps(): void
    {
        $tokenA = $this->loginToken('fixture-account-a', 'fixture-password-a', self::CORRELATION_A);
        $userIdA = $this->userIdFor('fixture-account-a');
        $headers = ['X-Correlation-ID' => self::CORRELATION_A];
        $this->seedStep($userIdA, 'active');
        $otherAssignee = (string) Str::uuid7();
        $this->seedStep($otherAssignee, 'active');

        $response = $this->withToken($tokenA)->getJson('/api/v1/workflow/steps?assignee=me&state=active', $headers);
        $response->assertOk();
        // WorkflowStepCollection is a bare collection in the contract, like every
        // other list endpoint; the `data` envelope is for single entities only.
        $this->assertCount(1, $response->json('items'));
        $this->assertSame($userIdA, $response->json('items.0.assignee_user_id'));
    }

    public function test_inbox_rejects_unauthenticated_callers(): void
    {
        $headers = ['X-Correlation-ID' => '018f6f7d-0c00-7000-8000-0000000005ff'];
        $response = $this->getJson('/api/v1/workflow/steps?assignee=me', $headers);
        $response->assertStatus(401);
    }

    public function test_inbox_state_all_has_a_stable_cursor_without_cross_principal_rows(): void
    {
        $tokenA = $this->loginToken('fixture-account-a', 'fixture-password-a', self::CORRELATION_A);
        $userIdA = $this->userIdFor('fixture-account-a');
        $headers = ['X-Correlation-ID' => self::CORRELATION_A];
        $this->seedStep($userIdA, 'active');
        $this->seedStep($userIdA, 'completed');
        $this->seedStep((string) Str::uuid7(), 'active');

        $first = $this->withToken($tokenA)->getJson('/api/v1/workflow/steps?assignee=me&state=all&limit=1', $headers)->assertOk();
        $cursor = $first->json('next_cursor');
        $this->assertIsString($cursor);
        $this->assertCount(1, $first->json('items'));
        $this->assertSame($userIdA, $first->json('items.0.assignee_user_id'));

        $second = $this->withToken($tokenA)->getJson('/api/v1/workflow/steps?assignee=me&state=all&limit=1&cursor='.urlencode($cursor), $headers)->assertOk();
        $this->assertCount(1, $second->json('items'));
        $this->assertSame($userIdA, $second->json('items.0.assignee_user_id'));
        $this->assertNotSame($first->json('items.0.step_id'), $second->json('items.0.step_id'));
        $this->assertNull($second->json('next_cursor'));
    }

    public function test_inbox_allowed_actions_follow_the_authorization_decision(): void
    {
        $tokenA = $this->loginToken('fixture-account-a', 'fixture-password-a', self::CORRELATION_A);
        $userIdA = $this->userIdFor('fixture-account-a');
        $this->seedStep($userIdA, 'active');
        $this->app->instance(DecideAccess::class, $this->decisions(['workflow.reassign', 'workflow.escalate']));

        $response = $this->withToken($tokenA)->getJson('/api/v1/workflow/steps?assignee=me&state=active', ['X-Correlation-ID' => self::CORRELATION_A])->assertOk();

        $this->assertSame(['approve', 'reject', 'return'], $response->json('items.0.allowed_actions'));
    }

    public function test_operations_inbox_returns_only_steps_with_source_facts_inside_approval_scope(): void
    {
        $tokenA = $this->loginToken('fixture-account-a', 'fixture-password-a', self::CORRELATION_A);
        $targetAssignee = (string) Str::uuid7();
        $coveredFacility = '0197f0e0-0000-7000-8000-0000000000c1';
        $coveredSource = $this->seedWorkRecord($coveredFacility);
        $outsideSource = $this->seedWorkRecord('0197f0e0-0000-7000-8000-0000000000c2');
        $coveredInstance = $this->seedInstance((string) Str::uuid7(), $targetAssignee, 'work_records', 'work_record', $coveredSource);
        $this->seedInstance((string) Str::uuid7(), $targetAssignee, 'work_records', 'work_record', $outsideSource);
        $this->app->instance(DecideAccess::class, $this->approvalOnlyForFacility($coveredFacility));

        $response = $this->withToken($tokenA)->getJson('/api/v1/workflow/steps?assignee_user_id='.$targetAssignee.'&state=all', ['X-Correlation-ID' => self::CORRELATION_A])->assertOk();

        $this->assertSame([$coveredInstance], $response->json('items.*.workflow_instance_id'));
        $this->assertSame([[]], $response->json('items.*.allowed_actions'));
    }

    public function test_operations_inbox_fails_closed_when_source_facts_cannot_be_resolved(): void
    {
        $tokenA = $this->loginToken('fixture-account-a', 'fixture-password-a', self::CORRELATION_A);
        $targetAssignee = (string) Str::uuid7();
        $this->seedInstance((string) Str::uuid7(), $targetAssignee, 'unknown-module', 'unknown_type', 'unknown-source');
        $this->app->instance(DecideAccess::class, $this->approvalOnlyForFacility('0197f0e0-0000-7000-8000-0000000000c1'));

        $this->withToken($tokenA)->getJson('/api/v1/workflow/steps?assignee_user_id='.$targetAssignee.'&state=all', ['X-Correlation-ID' => self::CORRELATION_A])
            ->assertOk()
            ->assertJsonPath('items', [])
            ->assertJsonPath('next_cursor', null);
    }

    public function test_instances_list_is_started_by_the_current_principal_in_sql(): void
    {
        $tokenA = $this->loginToken('fixture-account-a', 'fixture-password-a', self::CORRELATION_A);
        $userIdA = $this->userIdFor('fixture-account-a');
        $ownInstanceId = $this->seedInstance($userIdA, $userIdA);
        $foreignInstanceId = $this->seedInstance((string) Str::uuid7(), (string) Str::uuid7());

        $response = $this->withToken($tokenA)->getJson('/api/v1/workflow/instances', ['X-Correlation-ID' => self::CORRELATION_A])->assertOk();

        $this->assertContains($ownInstanceId, $response->json('items.*.id'));
        $this->assertNotContains($foreignInstanceId, $response->json('items.*.id'));
    }

    public function test_show_instance_hides_other_users_records(): void
    {
        $tokenA = $this->loginToken('fixture-account-a', 'fixture-password-a', self::CORRELATION_A);
        $userIdA = $this->userIdFor('fixture-account-a');
        $otherAssignee = (string) Str::uuid7();
        // Seed two instances: one whose body the caller owns, one they must not see.
        $ownInstanceId = $this->seedInstance($userIdA, $userIdA);
        $privateInstanceId = $this->seedInstance($otherAssignee, $otherAssignee);
        $headers = ['X-Correlation-ID' => self::CORRELATION_A];
        $this->app->instance(DecideAccess::class, $this->decisions(['workflow.approve']));

        $ownResponse = $this->withToken($tokenA)->getJson('/api/v1/workflow/instances/'.$ownInstanceId, $headers);
        $ownResponse->assertOk();
        $this->assertSame($ownInstanceId, $ownResponse->json('id'));
        $this->assertSame('workflow_instance', $ownResponse->json('resource_type'));
        $this->assertArrayHasKey('step_history', $ownResponse->json());

        $payload = $ownResponse->json('step_history');
        $this->assertIsArray($payload);
        foreach ($payload as $step) {
            $this->assertSame($userIdA, $step['assignee_user_id']);
        }
        $this->withToken($tokenA)->getJson('/api/v1/workflow/instances/'.$privateInstanceId, $headers)
            ->assertNotFound()
            ->assertJsonMissingPath('data.instance.id')
            ->assertJsonMissingPath('instance_id');
    }

    public function test_show_instance_allows_starters_and_assignees(): void
    {
        $tokenA = $this->loginToken('fixture-account-a', 'fixture-password-a', self::CORRELATION_A);
        $userIdA = $this->userIdFor('fixture-account-a');
        $instanceId = $this->seedInstance((string) Str::uuid7(), $userIdA);
        $headers = ['X-Correlation-ID' => self::CORRELATION_A];

        $response = $this->withToken($tokenA)->getJson('/api/v1/workflow/instances/'.$instanceId, $headers);
        $response->assertOk();
    }

    public function test_step_detail_returns_the_authorized_step_with_instance_context(): void
    {
        $tokenA = $this->loginToken('fixture-account-a', 'fixture-password-a', self::CORRELATION_A);
        $userIdA = $this->userIdFor('fixture-account-a');
        $instanceId = $this->seedInstance((string) Str::uuid7(), $userIdA);
        $stepId = (string) DB::table('workflow_step_instances')->where('workflow_instance_id', $instanceId)->value('id');

        $this->withToken($tokenA)->getJson('/api/v1/workflow/steps/'.$stepId, ['X-Correlation-ID' => self::CORRELATION_A])
            ->assertOk()
            ->assertJsonPath('step_id', $stepId)
            ->assertJsonPath('workflow_instance.id', $instanceId)
            ->assertJsonPath('lock_version', 1);
    }

    public function test_instances_list_paginates_owned_rows_with_equal_timestamps_without_foreign_rows(): void
    {
        $tokenA = $this->loginToken('fixture-account-a', 'fixture-password-a', self::CORRELATION_A);
        $userIdA = $this->userIdFor('fixture-account-a');
        $first = $this->seedInstance($userIdA, $userIdA);
        $second = $this->seedInstance($userIdA, $userIdA);
        $foreign = $this->seedInstance((string) Str::uuid7(), (string) Str::uuid7());
        $sameTime = Carbon::parse('2026-07-22 12:00:00');
        DB::table('workflow_instances')->whereIn('id', [$first, $second, $foreign])->update(['created_at' => $sameTime]);

        $firstPage = $this->withToken($tokenA)->getJson('/api/v1/workflow/instances?limit=1', ['X-Correlation-ID' => self::CORRELATION_A])->assertOk();
        $cursor = $firstPage->json('next_cursor');
        $secondPage = $this->withToken($tokenA)->getJson('/api/v1/workflow/instances?limit=1&cursor='.urlencode((string) $cursor), ['X-Correlation-ID' => self::CORRELATION_A])->assertOk();

        $this->assertIsString($cursor);
        $this->assertNotSame($firstPage->json('items.0.id'), $secondPage->json('items.0.id'));
        $this->assertNotContains($foreign, [$firstPage->json('items.0.id'), $secondPage->json('items.0.id')]);
        $this->assertNull($secondPage->json('next_cursor'));
    }

    public function test_instances_list_filters_owned_instances_by_state_and_rejects_invalid_state_or_cursor(): void
    {
        $token = $this->loginToken('fixture-account-a', 'fixture-password-a', self::CORRELATION_A);
        $userId = $this->userIdFor('fixture-account-a');
        $running = $this->seedInstance($userId, $userId);
        $completed = $this->seedInstance($userId, $userId);
        DB::table('workflow_instances')->where('id', $completed)->update(['state' => 'completed']);
        $headers = ['X-Correlation-ID' => self::CORRELATION_A];

        $this->withToken($token)->getJson('/api/v1/workflow/instances?state=running', $headers)->assertOk()->assertJsonPath('items.0.id', $running);
        $this->withToken($token)->getJson('/api/v1/workflow/instances?state=completed', $headers)->assertOk()->assertJsonPath('items.0.id', $completed);
        $this->withToken($token)->getJson('/api/v1/workflow/instances?state=all', $headers)->assertStatus(400);
        $this->withToken($token)->getJson('/api/v1/workflow/instances?cursor=bad', $headers)->assertStatus(400);
        $invalidDateCursor = rtrim(strtr(base64_encode(json_encode([
            'created_at' => 'not-a-date',
            'id' => $running,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $this->withToken($token)->getJson('/api/v1/workflow/instances?cursor='.urlencode($invalidDateCursor), $headers)->assertStatus(400);
    }

    public function test_operations_review_fails_closed_when_workflow_source_facts_are_unavailable(): void
    {
        $tokenA = $this->loginToken('fixture-account-a', 'fixture-password-a', self::CORRELATION_A);
        $instanceId = $this->seedInstance((string) Str::uuid7(), (string) Str::uuid7(), 'unknown-module', 'unknown_type', 'unknown-source');
        $this->app->instance(DecideAccess::class, $this->decisions([]));

        $this->withToken($tokenA)->getJson('/api/v1/workflow/instances/'.$instanceId, ['X-Correlation-ID' => self::CORRELATION_A])
            ->assertNotFound()
            ->assertJsonMissingPath('instance_id');
    }

    public function test_operations_review_uses_covered_source_facts_not_the_principal_facility(): void
    {
        $tokenA = $this->loginToken('fixture-account-a', 'fixture-password-a', self::CORRELATION_A);
        $coveredFacility = '0197f0e0-0000-7000-8000-0000000000c1';
        $sourceId = $this->seedWorkRecord($coveredFacility);
        $instanceId = $this->seedInstance((string) Str::uuid7(), (string) Str::uuid7(), 'work_records', 'work_record', $sourceId);
        $this->app->instance(DecideAccess::class, $this->approvalOnlyForFacility($coveredFacility));

        $this->withToken($tokenA)->getJson('/api/v1/workflow/instances/'.$instanceId, ['X-Correlation-ID' => self::CORRELATION_A])
            ->assertOk()
            ->assertJsonPath('id', $instanceId);
    }

    public function test_operations_review_denies_source_facts_outside_the_approved_scope(): void
    {
        $tokenA = $this->loginToken('fixture-account-a', 'fixture-password-a', self::CORRELATION_A);
        $coveredFacility = '0197f0e0-0000-7000-8000-0000000000c1';
        $sourceId = $this->seedWorkRecord('0197f0e0-0000-7000-8000-0000000000c2');
        $instanceId = $this->seedInstance((string) Str::uuid7(), (string) Str::uuid7(), 'work_records', 'work_record', $sourceId);
        $this->app->instance(DecideAccess::class, $this->approvalOnlyForFacility($coveredFacility));

        $this->withToken($tokenA)->getJson('/api/v1/workflow/instances/'.$instanceId, ['X-Correlation-ID' => self::CORRELATION_A])
            ->assertNotFound()
            ->assertJsonMissingPath('instance_id');
    }

    public function test_show_instance_limits_an_assignee_to_their_related_steps(): void
    {
        $tokenA = $this->loginToken('fixture-account-a', 'fixture-password-a', self::CORRELATION_A);
        $userIdA = $this->userIdFor('fixture-account-a');
        $otherUser = (string) Str::uuid7();
        $instanceId = $this->seedInstance($otherUser, $userIdA);
        $this->seedStepForInstance($instanceId, $otherUser, 'active');

        $response = $this->withToken($tokenA)->getJson('/api/v1/workflow/instances/'.$instanceId, ['X-Correlation-ID' => self::CORRELATION_A])->assertOk();

        $this->assertSame([$userIdA], $response->json('step_history.*.assignee_user_id'));
    }

    private function loginToken(string $username, string $password, string $correlationId): string
    {
        $response = $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => $password], ['X-Correlation-ID' => $correlationId])->assertOk();

        return (string) $response->json('data.access_token');
    }

    private function userIdFor(string $username): string
    {
        $fixture = DB::table('identity_development_fixture_accounts')->where('username', $username)->first();
        $this->assertNotNull($fixture, 'Fixture account "'.$username.'" must exist.');

        return (string) $fixture->id;
    }

    private function seedStep(string $assignee, string $state): void
    {
        $instanceId = (string) Str::uuid7();
        $now = Carbon::now();
        DB::table('workflow_instances')->insert([
            'id' => $instanceId,
            'workflow_version_id' => (string) Str::uuid7(),
            'source_module' => 'work_records',
            'source_type' => 'work_record',
            'source_id' => (string) Str::uuid7(),
            'state' => 'running',
            'started_by_user_id' => (string) Str::uuid7(),
            'started_at' => $now,
            'completed_at' => null,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('workflow_step_instances')->insert([
            'id' => (string) Str::uuid7(),
            'workflow_instance_id' => $instanceId,
            'node_key' => 'review-1',
            'node_type' => 'work_item',
            'state' => $state,
            'activation_sequence' => 1,
            'activated_at' => $now,
            'completed_at' => null,
            'task_id' => null,
            'assignee_user_id' => $assignee,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedInstance(string $starter, string $assignee, string $sourceModule = 'work_records', string $sourceType = 'work_record', ?string $sourceId = null): string
    {
        $instanceId = (string) Str::uuid7();
        $now = Carbon::now();
        DB::table('workflow_instances')->insert([
            'id' => $instanceId,
            'workflow_version_id' => (string) Str::uuid7(),
            'source_module' => $sourceModule,
            'source_type' => $sourceType,
            'source_id' => $sourceId ?? (string) Str::uuid7(),
            'state' => 'running',
            'started_by_user_id' => $starter,
            'started_at' => $now,
            'completed_at' => null,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('workflow_step_instances')->insert([
            'id' => (string) Str::uuid7(),
            'workflow_instance_id' => $instanceId,
            'node_key' => 'review-1',
            'node_type' => 'work_item',
            'state' => 'active',
            'activation_sequence' => 1,
            'activated_at' => $now,
            'completed_at' => null,
            'task_id' => null,
            'assignee_user_id' => $assignee,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $instanceId;
    }

    private function seedStepForInstance(string $instanceId, string $assignee, string $state): void
    {
        $now = Carbon::now();
        DB::table('workflow_step_instances')->insert([
            'id' => (string) Str::uuid7(),
            'workflow_instance_id' => $instanceId,
            'node_key' => 'review-2',
            'node_type' => 'work_item',
            'state' => $state,
            'activation_sequence' => 1,
            'activated_at' => $now,
            'completed_at' => null,
            'task_id' => null,
            'assignee_user_id' => $assignee,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedWorkRecord(string $facilityId): string
    {
        $id = (string) Str::uuid7();
        $now = Carbon::now();
        DB::table('work_records')->insert([
            'id' => $id,
            'record_number' => 'WR-'.Str::uuid7(),
            'work_type_version_id' => (string) Str::uuid7(),
            'owner_facility_id' => $facilityId,
            'creator_user_id' => (string) Str::uuid7(),
            'status' => 'submitted',
            'classification' => 'internal',
            'payload' => json_encode([], JSON_THROW_ON_ERROR),
            'lock_version' => 1,
            'submitted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /** @param list<string> $deniedCapabilities */
    private function decisions(array $deniedCapabilities): DecideAccess
    {
        return new class($deniedCapabilities) implements DecideAccess
        {
            /** @param list<string> $deniedCapabilities */
            public function __construct(private readonly array $deniedCapabilities) {}

            public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                return new AccessDecision(
                    decision: in_array($capability, $this->deniedCapabilities, true) ? 'deny' : 'allow',
                    action: $capability,
                    resourceType: $facts->resourceType ?? 'workflow_instance',
                    reasonCodes: ['test-decision'],
                    policyVersion: 'test',
                    factsVersion: $facts->factsVersion ?? 'test',
                    classification: $facts->classification ?? 'internal',
                );
            }
        };
    }

    private function approvalOnlyForFacility(string $facilityId): DecideAccess
    {
        return new class($facilityId) implements DecideAccess
        {
            public function __construct(private readonly string $facilityId) {}

            public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                $allowed = $capability !== 'workflow.approve'
                    || $facts?->recordId === null
                    || $facts->ownerFacilityId === $this->facilityId;

                return new AccessDecision(
                    decision: $allowed ? 'allow' : 'deny',
                    action: $capability,
                    resourceType: $facts->resourceType ?? 'workflow_instance',
                    reasonCodes: ['test-decision'],
                    policyVersion: 'test',
                    factsVersion: $facts->factsVersion ?? 'test',
                    classification: $facts->classification ?? 'internal',
                );
            }
        };
    }
}
