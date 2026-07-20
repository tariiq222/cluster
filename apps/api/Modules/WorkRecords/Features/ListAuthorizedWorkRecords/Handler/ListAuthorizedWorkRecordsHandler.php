<?php

namespace Modules\WorkRecords\Features\ListAuthorizedWorkRecords\Handler;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Modules\Authorization\Contracts\AccessProjection;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use stdClass;

final class ListAuthorizedWorkRecordsHandler
{
    public function __construct(
        private readonly DecideAccess $access,
        private readonly ResolveOrganizationScopeAncestry $ancestry,
    ) {}

    /**
     * @param  array{user_id: string, facility_id: string}  $principal
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function handle(
        array $principal,
        ?string $cursor = null,
        int $limit = 25,
        ?string $classification = null,
    ): array {
        $afterId = $cursor === null
            ? null
            : $this->decodeCursor($cursor, $principal, $limit, $classification);
        $query = DB::table('work_records')->orderBy('id');
        // Scope predicate before pagination: the principal's facility scopes
        // bound the SQL read; the central decision still authorizes each row.
        $facilityScopes = DB::table('role_assignments')
            ->where('user_id', $principal['user_id'])
            ->where('scope_type', 'facility')
            ->where('status', 'active')
            ->where('start_at', '<=', now()->utc())
            ->where(fn ($query) => $query->whereNull('end_at')->orWhere('end_at', '>', now()->utc()))
            ->pluck('scope_id')
            ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();
        // Legacy fixture principals carry their home facility directly and
        // have no role assignments yet; keep them on their home scope until
        // the real grant catalog covers them.
        if ($facilityScopes === [] && $principal['facility_id'] !== '') {
            $facilityScopes = [$principal['facility_id']];
        }
        $query->whereIn('owner_facility_id', $facilityScopes);
        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }
        if ($classification !== null) {
            $query->where('classification', $classification);
        }

        $authorized = [];
        foreach ($query->get() as $row) {
            $decision = $this->access->decide(
                $this->actor($principal),
                'work_record.list',
                $this->factsFor($row),
            );

            if ($decision->isAllowed()) {
                $authorized[] = $this->serialize($row, AccessProjection::fromDecision($decision));
                if (count($authorized) > $limit) {
                    break;
                }
            }
        }

        $hasNextPage = count($authorized) > $limit;
        if ($hasNextPage) {
            array_pop($authorized);
        }

        return [
            'items' => $authorized,
            'next_cursor' => $hasNextPage
                ? $this->encodeCursor(
                    $authorized[array_key_last($authorized)]['id'],
                    $principal,
                    $limit,
                    $classification,
                )
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(stdClass $row, AccessProjection $projection): array
    {
        return $projection->compose([
            'id' => $row->id,
            'record_number' => $row->record_number,
            'work_type_version_id' => $row->work_type_version_id,
            'owner' => [
                'facility_id' => $row->owner_facility_id,
                'user_id' => $row->creator_user_id,
            ],
            'status' => $row->status,
            'classification' => $row->classification,
            'payload' => json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR),
            'lock_version' => (int) $row->lock_version,
            'submitted_at' => $this->timestamp($row->submitted_at),
            'created_at' => $this->timestamp($row->created_at),
            'updated_at' => $this->timestamp($row->updated_at),
        ], function (array $payload, array $fieldAccess): array {
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
        });
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function actor(array $principal): array
    {
        return [
            'user_id' => $principal['user_id'],
            'facility_id' => $principal['facility_id'],
            'facility_ids' => is_array($principal['facility_ids'] ?? null) ? $principal['facility_ids'] : [$principal['facility_id']],
            'organization_unit_ids' => is_array($principal['organization_unit_ids'] ?? null) ? $principal['organization_unit_ids'] : [],
        ];
    }

    private function factsFor(stdClass $row): RecordFacts
    {
        $ancestry = $this->ancestry->ancestry('facility', (string) $row->owner_facility_id);

        return new RecordFacts(
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
        );
    }

    private function timestamp(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function encodeCursor(
        string $recordId,
        array $principal,
        int $limit,
        ?string $classification,
    ): string {
        return Crypt::encryptString(json_encode([
            'version' => 1,
            'after_id' => $recordId,
            'query' => [
                'limit' => $limit,
                'classification' => $classification,
            ],
            'scope' => [
                'principal_id' => $principal['user_id'],
                'facility_id' => $principal['facility_id'],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function decodeCursor(
        string $cursor,
        array $principal,
        int $limit,
        ?string $classification,
    ): string {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new InvalidArgumentException('The pagination cursor is invalid.');
        }

        if (! is_array($payload)
            || array_keys($payload) !== ['version', 'after_id', 'query', 'scope']
            || $payload['version'] !== 1
            || ! is_array($payload['query'])
            || array_keys($payload['query']) !== ['limit', 'classification']
            || $payload['query']['limit'] !== $limit
            || $payload['query']['classification'] !== $classification
            || ! is_array($payload['scope'])
            || array_keys($payload['scope']) !== ['principal_id', 'facility_id']
            || ! is_string($payload['scope']['principal_id'])
            || ! hash_equals($principal['user_id'], $payload['scope']['principal_id'])
            || ! is_string($payload['scope']['facility_id'])
            || ! hash_equals($principal['facility_id'], $payload['scope']['facility_id'])
            || ! is_string($payload['after_id'])
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $payload['after_id']) !== 1) {
            throw new InvalidArgumentException('The pagination cursor is invalid.');
        }

        return $payload['after_id'];
    }
}
