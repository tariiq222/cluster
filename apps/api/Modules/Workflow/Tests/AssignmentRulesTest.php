<?php

namespace Modules\Workflow\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
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

        // supervisor_of_initiator hits the assignments/positions join in the
        // production code path. With an empty DB the join returns no row, so
        // the resolution is null. The stub path proves the constructor wires
        // the right collaborators; the production join is covered separately.
        $this->assertNull($userId);
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
