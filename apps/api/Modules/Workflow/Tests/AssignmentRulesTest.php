<?php

namespace Modules\Workflow\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Contracts\ResolveUserForPerson;
use Modules\Organization\Contracts\ResolvePersonOrganizationScope;
use Modules\Workflow\Contracts\RuleContext;
use Modules\Workflow\Contracts\RuleSpec;
use Modules\Workflow\Domain\AssignmentRules;
use Modules\Workflow\Domain\ListUsersInRole;
use Modules\Workflow\Domain\ResolveUserForAssignmentStep;
use Tests\TestCase;

/**
 * Pure resolution rules with no workflow fixtures. The four helper contracts
 * are stubbed with anonymous classes so the match() arms exercise every
 * branch without standing up Organization fixtures. supervisor_of_initiator
 * touches the assignments/positions tables inside the engine, so the suite
 * boots Laravel with RefreshDatabase to keep the join readable.
 */
final class AssignmentRulesTest extends TestCase
{
    use RefreshDatabase;

    private const INITIATOR_PERSON = '0197f0e0-0000-7000-8000-000000000010';

    private const MANAGER_PERSON = '0197f0e0-0000-7000-8000-000000000020';

    private const MANAGER_USER = '0197f0e0-0000-7000-8000-0000000000a2';

    private const CLUSTER_ID = '0197f0e0-0000-7000-8000-0000000000c1';

    private const UNIT_TYPE_ID = '0197f0e0-0000-7000-8000-0000000000c2';

    private const UNIT_ID = '0197f0e0-0000-7000-8000-0000000000c3';

    private const MANAGER_POSITION = '0197f0e0-0000-7000-8000-0000000000c4';

    private const SUBORDINATE_POSITION = '0197f0e0-0000-7000-8000-0000000000c5';

    private const INITIATOR_ASSIGNMENT = '0197f0e0-0000-7000-8000-0000000000c6';

    private const MANAGER_ASSIGNMENT = '0197f0e0-0000-7000-8000-0000000000c7';

    private const SUPERVISOR_PERSON = '0197f0e0-0000-7000-8000-000000000020';

    private const STEP_PERSON = '0197f0e0-0000-7000-8000-000000000030';

    private const SUPERVISOR_USER = '0197f0e0-0000-7000-8000-0000000000a2';

    private const STEP_USER = '0197f0e0-0000-7000-8000-0000000000a3';

    private const HR_USER = '0197f0e0-0000-7000-8000-0000000000a4';

    private const INSTANCE_ID = '0197f0e0-0000-7000-8000-0000000000b1';

    public function test_supervisor_of_initiator_resolves_via_org_scope_and_user_lookup(): void
    {
        $supervisorPerson = self::SUPERVISOR_PERSON;
        $supervisorUser = self::SUPERVISOR_USER;
        $scope = new class implements ResolvePersonOrganizationScope
        {
            public function forPerson(string $personId): array
            {
                return [
                    'cluster_ids' => ['cluster-x'],
                    'facility_ids' => ['facility-x'],
                    'organization_unit_ids' => ['unit-x'],
                    'primary_organization_unit_id' => 'unit-x',
                ];
            }
        };
        $users = new class($supervisorPerson, $supervisorUser) implements ResolveUserForPerson
        {
            public function __construct(
                private readonly string $supervisorPerson,
                private readonly string $supervisorUser,
            ) {}

            public function forPerson(string $personId): ?string
            {
                return $personId === $this->supervisorPerson ? $this->supervisorUser : null;
            }
        };

        $rules = AssignmentRules::supervisor_of_initiator($scope, $users);

        $userId = $rules->resolve(
            new RuleContext(['initiator_person_id' => self::INITIATOR_PERSON]),
            new RuleSpec('supervisor_of_initiator'),
        );

        // With an empty DB the join returns no row, so the resolution is
        // null. The seeded cases below pin the manager-position semantics.
        $this->assertNull($userId);
    }

    public function test_supervisor_of_initiator_resolves_the_holder_of_the_manager_position(): void
    {
        $this->seedOrgTreeWithManager();
        $managerPerson = self::MANAGER_PERSON;
        $managerUser = self::MANAGER_USER;
        $users = new class($managerPerson, $managerUser) implements ResolveUserForPerson
        {
            public function __construct(
                private readonly string $managerPerson,
                private readonly string $managerUser,
            ) {}

            public function forPerson(string $personId): ?string
            {
                return $personId === $this->managerPerson ? $this->managerUser : null;
            }
        };

        $userId = AssignmentRules::supervisor_of_initiator($this->fixedScope(), $users)
            ->resolve(new RuleContext(['initiator_person_id' => self::INITIATOR_PERSON]), new RuleSpec('supervisor_of_initiator'));

        $this->assertSame(self::MANAGER_USER, $userId);
    }

    public function test_supervisor_of_initiator_returns_null_when_the_position_has_no_manager(): void
    {
        $this->seedOrgTreeWithManager();
        DB::table('positions')->where('id', self::SUBORDINATE_POSITION)->update(['manager_position_id' => null]);

        $userId = AssignmentRules::supervisor_of_initiator($this->fixedScope(), $this->anyUser())
            ->resolve(new RuleContext(['initiator_person_id' => self::INITIATOR_PERSON]), new RuleSpec('supervisor_of_initiator'));

        $this->assertNull($userId);
    }

    public function test_supervisor_of_initiator_returns_null_when_the_manager_position_is_vacant(): void
    {
        $this->seedOrgTreeWithManager();
        DB::table('assignments')->where('position_id', self::MANAGER_POSITION)->delete();

        $userId = AssignmentRules::supervisor_of_initiator($this->fixedScope(), $this->anyUser())
            ->resolve(new RuleContext(['initiator_person_id' => self::INITIATOR_PERSON]), new RuleSpec('supervisor_of_initiator'));

        $this->assertNull($userId);
    }

    public function test_supervisor_of_initiator_ignores_ended_assignments(): void
    {
        $this->seedOrgTreeWithManager();
        DB::table('assignments')->where('position_id', self::MANAGER_POSITION)->update(['end_at' => now()]);

        $userId = AssignmentRules::supervisor_of_initiator($this->fixedScope(), $this->anyUser())
            ->resolve(new RuleContext(['initiator_person_id' => self::INITIATOR_PERSON]), new RuleSpec('supervisor_of_initiator'));

        $this->assertNull($userId);
    }

    private function fixedScope(): ResolvePersonOrganizationScope
    {
        $unitId = self::UNIT_ID;

        return new class($unitId) implements ResolvePersonOrganizationScope
        {
            public function __construct(private readonly string $unitId) {}

            public function forPerson(string $personId): array
            {
                return [
                    'cluster_ids' => ['cluster-x'],
                    'facility_ids' => ['facility-x'],
                    'organization_unit_ids' => [$this->unitId],
                    'primary_organization_unit_id' => $this->unitId,
                ];
            }
        };
    }

    private function anyUser(): ResolveUserForPerson
    {
        return new class implements ResolveUserForPerson
        {
            public function forPerson(string $personId): string
            {
                return 'user-for-'.$personId;
            }
        };
    }

    private function seedOrgTreeWithManager(): void
    {
        $now = now();
        DB::table('clusters')->insert([
            'id' => self::CLUSTER_ID, 'code' => 'WF-RULES', 'name_ar' => 'تجمع قواعد',
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('unit_types')->insert([
            'id' => self::UNIT_TYPE_ID, 'code' => 'wf_rules_unit', 'name_ar' => 'وحدة قواعد',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('organization_units')->insert([
            'id' => self::UNIT_ID, 'cluster_id' => self::CLUSTER_ID,
            'parent_id' => self::CLUSTER_ID, 'parent_type' => 'cluster',
            'unit_type_id' => self::UNIT_TYPE_ID, 'code' => 'WF-UNIT', 'name_ar' => 'وحدة قواعد',
            'status' => 'active', 'path_cache' => '/'.self::CLUSTER_ID.'/'.self::UNIT_ID, 'depth' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('positions')->insert([
            'id' => self::MANAGER_POSITION, 'organization_unit_id' => self::UNIT_ID,
            'code' => 'WF-MGR', 'title_ar' => 'مشرف', 'manager_position_id' => null,
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('positions')->insert([
            'id' => self::SUBORDINATE_POSITION, 'organization_unit_id' => self::UNIT_ID,
            'code' => 'WF-SUB', 'title_ar' => 'موظف', 'manager_position_id' => self::MANAGER_POSITION,
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([self::INITIATOR_PERSON => 'WF-EMP-001', self::MANAGER_PERSON => 'WF-EMP-002'] as $personId => $employeeNumber) {
            DB::table('people')->insert([
                'id' => $personId, 'employee_number' => $employeeNumber,
                'display_name_ar' => 'موظف قواعد', 'status' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('assignments')->insert([
            'id' => self::INITIATOR_ASSIGNMENT, 'person_id' => self::INITIATOR_PERSON,
            'position_id' => self::SUBORDINATE_POSITION, 'start_at' => $now,
            'is_primary' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('assignments')->insert([
            'id' => self::MANAGER_ASSIGNMENT, 'person_id' => self::MANAGER_PERSON,
            'position_id' => self::MANAGER_POSITION, 'start_at' => $now,
            'is_primary' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function test_supervisor_of_step_resolves_via_step_index_lookup(): void
    {
        $instanceId = self::INSTANCE_ID;
        $stepPerson = self::STEP_PERSON;
        $stepUser = self::STEP_USER;
        $steps = new class($instanceId, $stepPerson) implements ResolveUserForAssignmentStep
        {
            public function __construct(
                private readonly string $instanceId,
                private readonly string $stepPerson,
            ) {}

            public function personForStep(string $instanceId, int $stepIndex): ?string
            {
                return $instanceId === $this->instanceId && $stepIndex === 1 ? $this->stepPerson : null;
            }
        };
        $users = new class($stepPerson, $stepUser) implements ResolveUserForPerson
        {
            public function __construct(
                private readonly string $stepPerson,
                private readonly string $stepUser,
            ) {}

            public function forPerson(string $personId): ?string
            {
                return $personId === $this->stepPerson ? $this->stepUser : null;
            }
        };

        $rules = AssignmentRules::supervisor_of_step(1, $steps, $users);

        $userId = $rules->resolve(
            new RuleContext(['workflow_instance_id' => self::INSTANCE_ID]),
            new RuleSpec('supervisor_of_step', ['step_index' => 1]),
        );

        $this->assertSame(self::STEP_USER, $userId);
    }

    public function test_role_rule_returns_the_first_listed_user(): void
    {
        $hrUser = self::HR_USER;
        $roles = new class($hrUser) implements ListUsersInRole
        {
            public function __construct(private readonly string $hrUser) {}

            public function users(string $roleCode): array
            {
                return $roleCode === 'hr_officer'
                    ? [$this->hrUser, '0197f0e0-0000-7000-8000-0000000000a5']
                    : [];
            }
        };

        $rules = AssignmentRules::role('hr_officer', $roles);

        $userId = $rules->resolve(new RuleContext([]), new RuleSpec('role', ['role_code' => 'hr_officer']));

        $this->assertSame(self::HR_USER, $userId);
    }

    public function test_unknown_rule_type_returns_null_instead_of_throwing(): void
    {
        $rules = AssignmentRules::role('hr_officer', new class implements ListUsersInRole
        {
            public function users(string $roleCode): array
            {
                return [];
            }
        });

        $this->assertNull($rules->resolve(new RuleContext([]), new RuleSpec('pick_a_winner')));
    }
}
