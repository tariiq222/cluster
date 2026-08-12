<?php

declare(strict_types=1);

namespace Modules\Tasks\Tests;

use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Documents\Contracts\DocumentSourceReference;
use Modules\Tasks\Application\TaskAccessPolicy;
use Modules\Tasks\Application\TaskAuthorizationFacts;
use Tests\TestCase;

final class TaskAuthorizationScopeTest extends TestCase
{
    use RefreshDatabase;

    private const CLUSTER_A = '018f6f7d-0000-7000-8000-000000000201';

    private const CLUSTER_B = '018f6f7d-0000-7000-8000-000000000202';

    private const FACILITY_A1 = '018f6f7d-0000-7000-8000-000000000211';

    private const FACILITY_A2 = '018f6f7d-0000-7000-8000-000000000212';

    private const FACILITY_B1 = '018f6f7d-0000-7000-8000-000000000213';

    private const UNIT_X = '018f6f7d-0000-7000-8000-000000000221';

    private const UNIT_Y = '018f6f7d-0000-7000-8000-000000000222';

    private const TASK_ID = '018f6f7d-0000-7000-8000-000000000231';

    private const FACILITY_TASK_ID = '018f6f7d-0000-7000-8000-000000000232';

    private const UNKNOWN_OWNER_ID = '018f6f7d-0000-7000-8000-000000000299';

    private const USER_ID = '018f6f7d-0000-7000-8000-000000000241';

    private const CREATOR_ID = '018f6f7d-0000-7000-8000-000000000242';

    private const ASSIGNEE_ID = '018f6f7d-0000-7000-8000-000000000243';

    private const PARTICIPANT_ID = '018f6f7d-0000-7000-8000-000000000244';

    private const ROLE_ID = '018f6f7d-0000-7000-8000-000000000251';

    private const ROLE_ASSIGNMENT_ID = '018f6f7d-0000-7000-8000-000000000252';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindRealAccessDecision();

        $this->seed(AuthorizationCatalogSeeder::class);
        $this->seedOrganizationTree();
        $this->seedTask();
        $this->seedReaderRole();
    }

    public function test_task_facts_normalize_owner_ancestry_and_scope_decisions(): void
    {
        $task = DB::table('tasks')->where('id', self::TASK_ID)->first();
        $this->assertNotNull($task);

        $policyFacts = $this->app->make(TaskAccessPolicy::class)->factsFor($task, [self::PARTICIPANT_ID]);
        $linkedFacts = $this->app->make(TaskAuthorizationFacts::class)->resolve(
            new DocumentSourceReference('tasks', 'task', self::TASK_ID),
        );
        $this->assertNotNull($linkedFacts);

        foreach ([$policyFacts, $linkedFacts] as $facts) {
            $this->assertSame(self::FACILITY_A1, $facts->ownerFacilityId);
            $this->assertSame(self::UNIT_X, $facts->organizationUnitId);
            $this->assertSame(self::CLUSTER_A, $facts->clusterId);
            $this->assertSame(self::TASK_ID, $facts->recordId);
            $this->assertSame(self::CREATOR_ID, $facts->createdByUserId);
            $this->assertSame(self::ASSIGNEE_ID, $facts->responsibleUserId);
            $this->assertSame([self::PARTICIPANT_ID], $facts->participantIds);
            $this->assertSame('open', $facts->lifecycleState);
            $this->assertSame('internal', $facts->classification);
            $this->assertSame(1, $facts->lockVersion);
        }

        $scopeCases = [
            ['cluster', self::CLUSTER_A, true],
            ['cluster', self::CLUSTER_B, false],
            ['facility', self::FACILITY_A1, true],
            ['facility', self::FACILITY_A2, false],
            ['unit', self::UNIT_X, true],
            ['unit', self::UNIT_Y, false],
        ];

        foreach ($scopeCases as [$scopeType, $scopeId, $expected]) {
            $this->assignReaderScope($scopeType, $scopeId);
            foreach (['policy' => $policyFacts, 'linked' => $linkedFacts] as $path => $facts) {
                $decision = $this->decider()->evaluateOnly(
                    ['user_id' => self::USER_ID],
                    'tasks.read',
                    $facts,
                );

                $this->assertSame(
                    $expected,
                    $decision->isAllowed(),
                    "Unexpected {$path} decision for {$scopeType}:{$scopeId}.",
                );
            }
        }
    }

    public function test_facility_owned_task_uses_authoritative_facility_ancestry(): void
    {
        $this->insertTask(self::FACILITY_TASK_ID, self::FACILITY_A1);
        $task = DB::table('tasks')->where('id', self::FACILITY_TASK_ID)->first();
        $this->assertNotNull($task);

        $policyFacts = $this->app->make(TaskAccessPolicy::class)->factsFor($task, []);
        $linkedFacts = $this->app->make(TaskAuthorizationFacts::class)->resolve(
            new DocumentSourceReference('tasks', 'task', self::FACILITY_TASK_ID),
        );
        $this->assertNotNull($linkedFacts);

        foreach ([$policyFacts, $linkedFacts] as $facts) {
            $this->assertSame(self::FACILITY_A1, $facts->ownerFacilityId);
            $this->assertSame(self::CLUSTER_A, $facts->clusterId);
            $this->assertNull($facts->organizationUnitId);
        }

        $scopeCases = [
            ['cluster', self::CLUSTER_A, true],
            ['cluster', self::CLUSTER_B, false],
            ['facility', self::FACILITY_A1, true],
            ['facility', self::FACILITY_A2, false],
        ];

        foreach ($scopeCases as [$scopeType, $scopeId, $expected]) {
            $this->assignReaderScope($scopeType, $scopeId);
            foreach (['policy' => $policyFacts, 'linked' => $linkedFacts] as $path => $facts) {
                $decision = $this->decider()->evaluateOnly(
                    ['user_id' => self::USER_ID],
                    'tasks.read',
                    $facts,
                );

                $this->assertSame(
                    $expected,
                    $decision->isAllowed(),
                    "Unexpected {$path} decision for {$scopeType}:{$scopeId}.",
                );
            }
        }
    }

    public function test_unknown_task_owner_fails_closed_for_all_organizational_facts(): void
    {
        $this->insertTask(self::FACILITY_TASK_ID, self::UNKNOWN_OWNER_ID);
        $task = DB::table('tasks')->where('id', self::FACILITY_TASK_ID)->first();
        $this->assertNotNull($task);

        $policyFacts = $this->app->make(TaskAccessPolicy::class)->factsFor($task, []);
        $linkedFacts = $this->app->make(TaskAuthorizationFacts::class)->resolve(
            new DocumentSourceReference('tasks', 'task', self::FACILITY_TASK_ID),
        );
        $this->assertNotNull($linkedFacts);

        foreach ([$policyFacts, $linkedFacts] as $facts) {
            $this->assertNull($facts->ownerFacilityId);
            $this->assertNull($facts->organizationUnitId);
            $this->assertNull($facts->clusterId);
        }
    }

    private function decider(): DecideAccess
    {
        return $this->app->make(DecideAccess::class);
    }

    private function assignReaderScope(string $scopeType, string $scopeId): void
    {
        DB::table('role_assignments')->where('user_id', self::USER_ID)->delete();
        DB::table('role_assignments')->insert([
            'id' => self::ROLE_ASSIGNMENT_ID,
            'user_id' => self::USER_ID,
            'role_id' => self::ROLE_ID,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'start_at' => now()->subMinute(),
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::CREATOR_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedReaderRole(): void
    {
        DB::table('roles')->insert([
            'id' => self::ROLE_ID,
            'code' => 'task-scope-reader',
            'name_ar' => 'قارئ نطاق المهام',
            'name_en' => 'Task scope reader',
            'role_type' => 'administrative',
            'status' => 'active',
            'is_system_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $capabilityId = DB::table('capabilities')->where('capability_code', 'tasks.read')->value('id');
        $this->assertIsString($capabilityId);
        DB::table('role_capabilities')->insert([
            'role_id' => self::ROLE_ID,
            'capability_id' => $capabilityId,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedTask(): void
    {
        $this->insertTask(self::TASK_ID, self::UNIT_X, self::PARTICIPANT_ID);
    }

    private function insertTask(string $taskId, string $ownerId, ?string $participantId = null): void
    {
        DB::table('tasks')->insert([
            'id' => $taskId,
            'title' => 'Scoped task',
            'description' => null,
            'created_by_user_id' => self::CREATOR_ID,
            'assignee_user_id' => self::ASSIGNEE_ID,
            'owner_organization_unit_id' => $ownerId,
            'status' => 'open',
            'priority' => 'normal',
            'classification' => 'internal',
            'completion_policy' => 'direct',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($participantId !== null) {
            DB::table('task_participants')->insert([
                'id' => $participantId,
                'task_id' => $taskId,
                'user_id' => $participantId,
                'role' => 'participant',
                'added_by_user_id' => self::CREATOR_ID,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedOrganizationTree(): void
    {
        $now = now();
        DB::table('facility_types')->insert([
            'id' => '018f6f7d-0000-7000-8000-000000000261',
            'code' => 'task-hospital',
            'name_ar' => 'مستشفى',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('unit_types')->insert([
            'id' => '018f6f7d-0000-7000-8000-000000000262',
            'code' => 'task-department',
            'name_ar' => 'إدارة',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([self::CLUSTER_A, self::CLUSTER_B] as $index => $clusterId) {
            DB::table('clusters')->insert([
                'id' => $clusterId,
                'singleton_key' => $index + 1,
                'code' => 'TASK-CLUSTER-'.($index + 1),
                'name_ar' => 'تجمع المهام '.($index + 1),
                'name_en' => 'Task cluster '.($index + 1),
                'status' => 'active',
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ([
            [self::FACILITY_A1, self::CLUSTER_A, 'A1'],
            [self::FACILITY_A2, self::CLUSTER_A, 'A2'],
            [self::FACILITY_B1, self::CLUSTER_B, 'B1'],
        ] as [$facilityId, $clusterId, $code]) {
            DB::table('facilities')->insert([
                'id' => $facilityId,
                'cluster_id' => $clusterId,
                'facility_type_id' => '018f6f7d-0000-7000-8000-000000000261',
                'code' => 'TASK-FACILITY-'.$code,
                'name_ar' => 'منشأة '.$code,
                'name_en' => 'Facility '.$code,
                'status' => 'active',
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ([
            [self::UNIT_X, self::CLUSTER_A, self::FACILITY_A1, 'X'],
            [self::UNIT_Y, self::CLUSTER_A, self::FACILITY_A2, 'Y'],
        ] as [$unitId, $clusterId, $facilityId, $code]) {
            DB::table('organization_units')->insert([
                'id' => $unitId,
                'cluster_id' => $clusterId,
                'parent_id' => $facilityId,
                'parent_type' => 'facility',
                'unit_type_id' => '018f6f7d-0000-7000-8000-000000000262',
                'code' => 'TASK-UNIT-'.$code,
                'name_ar' => 'وحدة '.$code,
                'name_en' => 'Unit '.$code,
                'status' => 'active',
                'path_cache' => '/'.$clusterId.'/'.$facilityId.'/'.$unitId,
                'depth' => 2,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
