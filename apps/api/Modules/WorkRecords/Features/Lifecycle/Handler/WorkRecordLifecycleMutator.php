<?php

namespace Modules\WorkRecords\Features\Lifecycle\Handler;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\AccessProjection;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\WorkRecords\Application\WorkRecordResourceFacts;
use Shared\Contracts\TransactionalOutbox;

/**
 * Owns the transactional writes for the work-record lifecycle. The HTTP
 * controller must not own DB transactions or Outbox writes per the
 * module boundary rules.
 */
final class WorkRecordLifecycleMutator
{
    private const TRANSITIONS = [
        'submit' => ['from' => ['returned'], 'to' => 'submitted', 'capability' => 'work_record.submit'],
        'return' => ['from' => ['submitted'], 'to' => 'returned', 'capability' => 'work_record.return'],
        'complete' => ['from' => ['submitted'], 'to' => 'completed', 'capability' => 'work_record.complete'],
        // Legacy alias: `complete-submission` is the same transition as
        // `complete` (identical from-state, target, and capability). It is
        // kept because the web client and the OpenAPI contract still expose
        // the distinct action name; do not give it divergent semantics.
        'complete-submission' => ['from' => ['submitted'], 'to' => 'completed', 'capability' => 'work_record.complete'],
        'cancel' => ['from' => ['submitted', 'returned'], 'to' => 'cancelled', 'capability' => 'work_record.cancel'],
        'archive' => ['from' => ['completed', 'cancelled'], 'to' => 'archived', 'capability' => 'work_record.archive'],
    ];

    public function __construct(
        private readonly TransactionalOutbox $outbox,
        private readonly DecideAccess $access,
        private readonly WorkRecordResourceFacts $factsBuilder,
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

        $transition = self::TRANSITIONS[$action] ?? null;
        if ($transition === null) {
            return ['ok' => false, 'problem' => ['status' => 409, 'type' => 'invalid-record-transition', 'detail' => 'The record action is not supported.']];
        }
        $capability = $transition['capability'];

        $decision = $this->access->decide(
            [
                'user_id' => $principal['user_id'],
                'facility_id' => $principal['facility_id'],
                'organization_unit_ids' => array_filter([$principal['facility_id'] ?? null]),
                'correlation_id' => $correlationId,
            ],
            $capability,
            $this->factsBuilder->forRecord($row),
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
        if (! in_array((string) $row->status, $transition['from'], true)) {
            return ['ok' => false, 'problem' => ['status' => 409, 'type' => 'invalid-record-transition', 'detail' => 'The record action is not valid for the current state.']];
        }

        $status = $transition['to'];
        $submittedAt = $row->submitted_at;

        try {
            DB::transaction(function () use ($recordId, $expectedLockVersion, $status, $submittedAt, $action, $principal): void {
                $updates = [
                    'status' => $status,
                    'lock_version' => $expectedLockVersion + 1,
                    'updated_at' => now(),
                ];
                if ($status === 'submitted' && $submittedAt === null) {
                    $updates['submitted_at'] = now();
                }
                $updated = DB::table('work_records')->where('id', $recordId)->where('lock_version', $expectedLockVersion)->update($updates);
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
