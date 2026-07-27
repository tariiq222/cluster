<?php

namespace Modules\Workflow\Domain;

use Illuminate\Support\Facades\DB;
use Modules\Identity\Contracts\ResolveUserForPerson;
use Modules\Organization\Contracts\ResolvePersonOrganizationScope;
use Modules\Workflow\Contracts\ResolveStepAssignee;
use Modules\Workflow\Contracts\RuleContext;
use Modules\Workflow\Contracts\RuleSpec;

interface ResolveUserForAssignmentStep
{
    public function personForStep(string $instanceId, int $stepIndex): ?string;
}

interface ListUsersInRole
{
    /** @return list<string> */
    public function users(string $roleCode): array;
}

final class AssignmentRules implements ResolveStepAssignee
{
    private const INITIATOR_PERSON = 'initiator_person_id';

    private const INSTANCE_ID = 'workflow_instance_id';

    public function __construct(
        private readonly ?ResolvePersonOrganizationScope $scope = null,
        private readonly ?ResolveUserForPerson $user = null,
        private readonly ?ResolveUserForAssignmentStep $steps = null,
        private readonly ?ListUsersInRole $roles = null,
    ) {}

    public static function supervisor_of_initiator(ResolvePersonOrganizationScope $scope, ResolveUserForPerson $user): static
    {
        return new self($scope, $user);
    }

    public static function supervisor_of_step(int $stepIndex, ResolveUserForAssignmentStep $steps, ResolveUserForPerson $user): static
    {
        return new static(null, $user, $steps, null);
    }

    public static function role(string $roleCode, ListUsersInRole $roles): static
    {
        return new static(null, null, null, $roles);
    }

    public function resolve(RuleContext $ctx, RuleSpec $spec): ?string
    {
        return match ($spec->type) {
            'supervisor_of_initiator' => $this->resolveInitiator($ctx),
            'supervisor_of_step' => $this->resolveStep($ctx, $spec),
            'role' => $this->roles?->users((string) ($spec->arguments['role_code'] ?? $spec->arguments['role'] ?? ''))[0] ?? null,
            default => null,
        };
    }

    private function resolveInitiator(RuleContext $ctx): ?string
    {
        $personId = $ctx->get(self::INITIATOR_PERSON);
        if (! is_string($personId) || $personId === '' || $this->scope === null || $this->user === null) {
            return null;
        }
        $scope = $this->scope->forPerson($personId);
        $unitId = $scope['primary_organization_unit_id'] ?? null;
        if (! is_string($unitId) || $unitId === '') {
            return null;
        }
        // The supervisor is the holder of the manager position of the
        // initiator's own position in their primary unit — not merely any
        // managed position in the unit. Ordering by is_primary keeps the
        // pick deterministic when several active rows qualify.
        $managerPositionId = DB::table('assignments as assignment')
            ->join('positions as position', 'position.id', '=', 'assignment.position_id')
            ->where('assignment.person_id', $personId)
            ->where('position.organization_unit_id', $unitId)
            ->whereNull('assignment.end_at')
            ->orderByDesc('assignment.is_primary')
            ->value('position.manager_position_id');
        if (! is_string($managerPositionId) || $managerPositionId === '') {
            return null;
        }
        $managerPersonId = DB::table('assignments')
            ->where('position_id', $managerPositionId)
            ->whereNull('end_at')
            ->orderByDesc('is_primary')
            ->value('person_id');

        return is_string($managerPersonId) ? $this->user->forPerson($managerPersonId) : null;
    }

    private function resolveStep(RuleContext $ctx, RuleSpec $spec): ?string
    {
        if ($this->steps === null || $this->user === null) {
            return null;
        }
        $personId = $this->steps->personForStep((string) $ctx->get(self::INSTANCE_ID, ''), (int) ($spec->arguments['step_index'] ?? $spec->arguments['step'] ?? 0));

        return is_string($personId) ? $this->user->forPerson($personId) : null;
    }
}
