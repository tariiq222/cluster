<?php

declare(strict_types=1);

namespace Modules\Tasks\Application;

use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Documents\Contracts\DocumentSourceReference;
use Modules\Documents\Contracts\LinkedResourceAuthorizationFacts;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use stdClass;

final class TaskAuthorizationFacts implements LinkedResourceAuthorizationFacts
{
    public function __construct(private readonly ResolveOrganizationScopeAncestry $ancestry) {}

    public function resolve(DocumentSourceReference $reference): ?RecordFacts
    {
        if ($reference->sourceModule !== 'tasks' || $reference->sourceType !== 'task') {
            return null;
        }
        $task = DB::table('tasks')->where('id', $reference->sourceId)->first();
        if (! $task instanceof stdClass) {
            return null;
        }

        $ancestry = $this->resolveOwnerAncestry($task);
        $participantIds = DB::table('task_participants')
            ->where('task_id', (string) $task->id)
            ->pluck('user_id')
            ->filter(static fn (mixed $userId): bool => is_string($userId))
            ->values()
            ->all();

        return new RecordFacts(
            ownerFacilityId: $ancestry['facility_id'] ?? null,
            resourceType: 'task',
            classification: (string) $task->classification,
            organizationUnitId: $ancestry['unit_id'] ?? null,
            recordId: (string) $task->id,
            clusterId: $ancestry['cluster_id'] ?? null,
            createdByUserId: (string) $task->created_by_user_id,
            responsibleUserId: (string) $task->assignee_user_id,
            participantIds: $participantIds,
            lifecycleState: (string) $task->status,
            lockVersion: (int) $task->lock_version,
        );
    }

    /** @return array{cluster_id: ?string, facility_id: ?string, unit_id: ?string}|null */
    private function resolveOwnerAncestry(stdClass $task): ?array
    {
        if ($task->owner_organization_unit_id === null) {
            return null;
        }

        $ownerId = (string) $task->owner_organization_unit_id;

        return $this->ancestry->ancestry('unit', $ownerId)
            ?? $this->ancestry->ancestry('facility', $ownerId);
    }
}
