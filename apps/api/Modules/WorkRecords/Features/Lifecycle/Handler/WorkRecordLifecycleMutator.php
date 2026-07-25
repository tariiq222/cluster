<?php

namespace Modules\WorkRecords\Features\Lifecycle\Handler;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\AccessProjection;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use Shared\Contracts\TransactionalOutbox;

/**
 * Owns the transactional writes for the work-record lifecycle. The HTTP
 * controller must not own DB transactions or Outbox writes per the
 * module boundary rules.
 */
final class WorkRecordLifecycleMutator
{
    public function __construct(
        private readonly TransactionalOutbox $outbox,
        private readonly DecideAccess $access,
        private readonly ResolveOrganizationScopeAncestry $ancestry,
    ) {}

    /**
     * @return array{ok: true, result: array<string, mixed>, decision: \Modules\Authorization\Contracts\AccessDecision, capability: string, access_projection: \Modules\Authorization\Contracts\AccessProjection}|array{ok: false, problem: array{status: int, type: string, detail: string}}
     */
    public function transition(string $recordId, string $action, array $principal, string $correlationId, int $expectedLockVersion): array
    {
        $row = DB::table('work_records')->where('id', $recordId)->first();
        if ($row === null) {
            return ['ok' => false, 'problem' => ['status' => 404, 'type' => 'resource-not-found', 'detail' => 'The work record is not available.']];
        }

        $capability = match ($action) {
            'submit' => 'work_record.submit',
            'return' => 'work_record.return',
            'complete', 'complete-submission' => 'work_record.complete',
            'cancel' => 'work_record.cancel',
            'archive' => 'work_record.archive',
            default => null,
        };
        if ($capability === null) {
            return ['ok' => false, 'problem' => ['status' => 409, 'type' => 'invalid-record-transition', 'detail' => 'The record action is not supported.']];
        }

        $ancestry = $this->ancestry->ancestry('facility', (string) $row->owner_facility_id);
        $decision = $this->access->decide(
            [
                'user_id' => $principal['user_id'],
                'facility_id' => $principal['facility_id'],
                'organization_unit_ids' => array_filter([$principal['facility_id'] ?? null]),
                'correlation_id' => $correlationId,
            ],
            $capability,
            new RecordFacts(
                ownerFacilityId: $row->owner_facility_id,
                resourceType: 'work_record',
                classification: $row->classification,
                clusterId: $ancestry['cluster_id'] ?? null,
                recordId: (string) $row->id,
                createdByUserId: (string) $row->creator_user_id,
                lifecycleState: (string) $row->status,
                fieldPolicyKey: $row->field_policy_key ?? null,
                workTypeVersionId: (string) $row->work_type_version_id,
                lockVersion: (int) $row->lock_version,
            ),
        );
        if (! $decision->isAllowed()) {
            return ['ok' => false, 'problem' => ['status' => 403, 'type' => 'access-denied', 'detail' => 'Access denied.']];
        }
        if ($row->creator_user_id !== $principal['user_id']) {
            return ['ok' => false, 'problem' => ['status' => 404, 'type' => 'resource-not-found', 'detail' => 'The work record is not available.']];
        }
        if ($expectedLockVersion !== (int) $row->lock_version) {
            return ['ok' => false, 'problem' => ['status' => 412, 'type' => 'precondition-failed', 'detail' => 'If-Match does not match the current version.']];
        }

        $status = match ($action) {
            'submit' => 'submitted',
            'return' => 'returned',
            'complete', 'complete-submission' => 'completed',
            'cancel' => 'cancelled',
            default => 'archived',
        };

        try {
            DB::transaction(function () use ($recordId, $expectedLockVersion, $status, $action, $principal): void {
                $updated = DB::table('work_records')->where('id', $recordId)->where('lock_version', $expectedLockVersion)->update([
                    'status' => $status,
                    'lock_version' => $expectedLockVersion + 1,
                    'updated_at' => now(),
                    'submitted_at' => $status === 'submitted' ? now() : null,
                ]);
                if ($updated !== 1) {
                    throw new \RuntimeException('stale');
                }
                $this->outbox->append(Str::uuid7()->toString(), $recordId, 'work_record.'.$action.'.v1', [
                    'work_record_id' => $recordId,
                    'actor_user_id' => $principal['user_id'],
                ]);
            });
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'problem' => ['status' => 409, 'type' => 'work-record-stale', 'detail' => $e->getMessage()]];
        }

        $result = (array) DB::table('work_records')->where('id', $recordId)->first();

        return [
            'ok' => true,
            'result' => $result,
            'decision' => $decision,
            'capability' => $capability,
            'access_projection' => AccessProjection::fromDecision($decision),
        ];
    }
}
