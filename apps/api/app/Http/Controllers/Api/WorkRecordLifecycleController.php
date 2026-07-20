<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\AccessProjection;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use Shared\Contracts\TransactionalOutbox;

final class WorkRecordLifecycleController
{
    use HttpSupport;

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $resolver,
        private readonly TransactionalOutbox $outbox,
        private readonly DecideAccess $access,
        private readonly ResolveOrganizationScopeAncestry $ancestry,
    ) {}

    public function transition(Request $request, string $recordId, string $action): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        } $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        } $key = $this->commandHeaders($request);
        if ($key === '') {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $c);
        } $row = DB::table('work_records')->where('id', $recordId)->first();
        if ($row === null) {
            return $this->problem(404, 'resource-not-found', 'The work record is not available.', $c);
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
            return $this->problem(409, 'invalid-record-transition', 'The record action is not supported.', $c);
        }
        $ancestry = $this->ancestry->ancestry('facility', (string) $row->owner_facility_id);
        $decision = $this->access->decide([
            'user_id' => $p['user_id'],
            'facility_id' => $p['facility_id'],
            'organization_unit_ids' => array_filter([$p['facility_id'] ?? null]),
            'correlation_id' => $c,
        ], $capability, new RecordFacts(
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
        ));
        if (! $decision->isAllowed()) {
            return $this->problem(403, 'access-denied', 'Access denied.', $c);
        }
        if ($row->creator_user_id !== $p['user_id']) {
            return $this->problem(404, 'resource-not-found', 'The work record is not available.', $c);
        } $expected = $this->versionFromMatch($request);
        if ($expected === null || $expected !== (int) $row->lock_version) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        } $status = match ($action) {
            'submit' => 'submitted',
            'return' => 'returned',
            'complete', 'complete-submission' => 'completed',
            'cancel' => 'cancelled',
            default => 'archived',
        };
        DB::transaction(function () use ($recordId, $expected, $status, $action, $p): void {
            $updated = DB::table('work_records')->where('id', $recordId)->where('lock_version', $expected)->update(['status' => $status, 'lock_version' => $expected + 1, 'updated_at' => now(), 'submitted_at' => $status === 'submitted' ? now() : null]);
            if ($updated !== 1) {
                throw new \RuntimeException('stale');
            } $this->outbox->append(Str::uuid7()->toString(), $recordId, 'work_record.'.$action.'.v1', ['work_record_id' => $recordId, 'actor_user_id' => $p['user_id']]);
        });
        $result = (array) DB::table('work_records')->where('id', $recordId)->first();

        return $this->response(
            AccessProjection::fromDecision($decision)->compose($this->serialize($result), function (array $payload, array $fieldAccess): array {
                $wildcard = $fieldAccess['*'] ?? null;
                foreach ($payload as $field => $value) {
                    $state = $fieldAccess[$field] ?? $wildcard;
                    if ($state === 'hidden') {
                        unset($payload[$field]);
                    } elseif ($state === 'masked') {
                        $payload[$field] = '***';
                    }
                }

                return $payload;
            }),
            200,
            $c,
            (int) $result['lock_version'],
        );
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function serialize(array $row): array
    {
        $payload = $row['payload'] ?? [];
        if (is_string($payload)) {
            $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        }

        return [
            'id' => $row['id'],
            'record_number' => $row['record_number'],
            'work_type_version_id' => $row['work_type_version_id'],
            'owner' => [
                'facility_id' => $row['owner_facility_id'],
                'user_id' => $row['creator_user_id'],
            ],
            'status' => $row['status'],
            'classification' => $row['classification'],
            'payload' => is_array($payload) ? $payload : [],
            'lock_version' => (int) $row['lock_version'],
            'submitted_at' => $row['submitted_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
