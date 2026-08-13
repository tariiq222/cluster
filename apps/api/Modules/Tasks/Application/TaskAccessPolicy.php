<?php

declare(strict_types=1);

namespace Modules\Tasks\Application;

use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolvePersonForUser;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use Modules\Organization\Contracts\ResolvePersonOrganizationScope;
use Modules\Tasks\Infrastructure\Persistence\TaskHttpStore;
use stdClass;

/**
 * Server-computed allowed actions for a task. The UI must not infer authority
 * from the lifecycle state alone — every entry here is the result of an
 * authorization decision evaluated against the Tasks-owned row plus the
 * principal's relationship to it.
 */
final class TaskAccessPolicy
{
    /** @var list<string> */
    private const ALL_ACTIONS = [
        'start', 'block', 'unblock', 'complete', 'cancel',
        'edit', 'reassign', 'add-participant', 'comment', 'attach-document',
    ];

    public function __construct(
        private readonly DecideAccess $access,
        private readonly TaskHttpStore $store,
        private readonly ResolveOrganizationScopeAncestry $ancestry,
        private readonly ResolvePersonForUser $personForUser,
        private readonly ResolvePersonOrganizationScope $personScope,
    ) {}

    /**
     * @param  array{user_id: string, facility_id?: ?string}  $principal
     * @return list<string>
     */
    public function allowedActions(stdClass $task, array $principal, string $correlationId): array
    {
        $actor = [
            'user_id' => $principal['user_id'],
            'facility_id' => $principal['facility_id'] ?? null,
            'organization_unit_ids' => array_filter([$principal['facility_id'] ?? null]),
            'correlation_id' => $correlationId,
        ];
        $participants = $this->store->participantIds((string) $task->id);
        $facts = $this->factsFor($task, $participants);

        $state = (string) $task->status;
        $isCreator = $principal['user_id'] === (string) $task->created_by_user_id;
        $isAssignee = $principal['user_id'] === (string) $task->assignee_user_id;
        $isParticipant = in_array($principal['user_id'], $participants, true);

        $allowed = [];
        foreach (self::ALL_ACTIONS as $action) {
            if (! $this->supportsAction($state, $action, $isCreator, $isAssignee, $isParticipant)) {
                continue;
            }
            $capability = $this->capabilityFor($action);
            if ($this->access->decide($actor, $capability, $facts)->isAllowed()) {
                $allowed[] = $action;
            }
        }

        return array_values(array_unique($allowed));
    }

    /**
     * @param  list<string>  $participants
     */
    public function factsFor(stdClass $task, array $participants): RecordFacts
    {
        $ancestry = $this->resolveOwnerAncestry($task);

        return new RecordFacts(
            ownerFacilityId: $ancestry['facility_id'] ?? null,
            resourceType: 'task',
            classification: (string) ($task->classification ?? 'internal'),
            organizationUnitId: $ancestry['unit_id'] ?? null,
            recordId: (string) $task->id,
            clusterId: $ancestry['cluster_id'] ?? null,
            createdByUserId: (string) $task->created_by_user_id,
            responsibleUserId: (string) $task->assignee_user_id,
            participantIds: array_values(array_filter($participants, 'is_string')),
            lifecycleState: (string) $task->status,
            lockVersion: (int) $task->lock_version,
        );
    }

    public function factsForRequestedScope(string $scopeType, string $scopeId): ?RecordFacts
    {
        $ancestry = $this->ancestry->ancestry($scopeType, $scopeId);
        if ($ancestry === null) {
            return null;
        }

        $resolvedScopeId = match ($scopeType) {
            'cluster' => $ancestry['cluster_id'],
            'facility' => $ancestry['facility_id'],
            'unit' => $ancestry['unit_id'],
            default => null,
        };
        if (! is_string($resolvedScopeId) || ! hash_equals($scopeId, $resolvedScopeId)) {
            return null;
        }

        return new RecordFacts(
            ownerFacilityId: $ancestry['facility_id'],
            resourceType: 'task',
            classification: 'internal',
            organizationUnitId: $ancestry['unit_id'],
            recordId: $scopeId,
            clusterId: $ancestry['cluster_id'],
        );
    }

    public function participantIsWithinOwnerFacility(stdClass $task, string $userId): bool
    {
        $owner = $this->resolveOwnerAncestry($task);
        $ownerFacilityId = $owner['facility_id'] ?? null;
        if (! is_string($ownerFacilityId) || $ownerFacilityId === '') {
            return false;
        }

        $personId = $this->personForUser->forUser($userId);
        if ($personId === null) {
            return false;
        }

        $scope = $this->personScope->forPerson($personId);

        return in_array($ownerFacilityId, $scope['facility_ids'], true);
    }

    /** @return array{cluster_id: ?string, facility_id: ?string, unit_id: ?string}|null */
    private function resolveOwnerAncestry(stdClass $task): ?array
    {
        if ($task->owner_organization_unit_id === null) {
            return null;
        }

        $ownerId = (string) $task->owner_organization_unit_id;

        return $this->ancestry->ancestry('unit', $ownerId)
            ?? $this->ancestry->ancestry('facility', $ownerId)
            ?? $this->ancestry->ancestry('cluster', $ownerId);
    }

    private function supportsAction(string $state, string $action, bool $isCreator, bool $isAssignee, bool $isParticipant): bool
    {
        if ($state === 'completed' || $state === 'cancelled') {
            return false;
        }

        return match ($action) {
            'start' => $state === 'open' && $isAssignee,
            'block' => $state === 'in_progress' && $isAssignee,
            'unblock' => $state === 'blocked' && ($isAssignee || $isCreator),
            'complete' => $state === 'in_progress' && $isAssignee,
            'cancel' => in_array($state, ['open', 'in_progress', 'blocked'], true) && ($isCreator || ! $isParticipant),
            'edit' => $isCreator,
            'reassign' => $isCreator,
            'add-participant' => $isCreator || $isAssignee,
            'comment' => $isCreator || $isAssignee || $isParticipant,
            'attach-document' => $isCreator || $isAssignee || $isParticipant,
            default => false,
        };
    }

    private function capabilityFor(string $action): string
    {
        return match ($action) {
            'start' => 'tasks.start',
            'block' => 'tasks.update',
            'unblock' => 'tasks.update',
            'complete' => 'tasks.complete',
            'cancel' => 'tasks.cancel',
            'edit' => 'tasks.update',
            'reassign' => 'tasks.assign',
            'add-participant' => 'tasks.participant-manage',
            'comment' => 'tasks.comment',
            'attach-document' => 'tasks.update',
            default => 'tasks.read',
        };
    }
}
