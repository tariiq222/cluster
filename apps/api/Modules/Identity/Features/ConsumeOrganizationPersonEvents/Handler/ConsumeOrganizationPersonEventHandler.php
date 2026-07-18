<?php

namespace Modules\Identity\Features\ConsumeOrganizationPersonEvents\Handler;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Identity\Infrastructure\Outbox\IdentityOutbox;
use Modules\Organization\Contracts\ValidatePersonReference;
use stdClass;

final class ConsumeOrganizationPersonEventHandler
{
    private const PROVISIONING = 'com.cluster.organization.identityprovisioningrequested.v1';

    private const ACCESS_STATUS = 'com.cluster.organization.personaccessstatuschanged.v1';

    private const PERSON_UPDATED = 'com.cluster.organization.personupdated.v1';

    public function __construct(
        private readonly ValidatePersonReference $people,
        private readonly IdentityOutbox $outbox,
    ) {}

    /** @param array<string, mixed> $cloudEvent */
    public function handle(array $cloudEvent): bool
    {
        $this->validate($cloudEvent);
        if ($this->inboxContains($cloudEvent['id'])) {
            return false;
        }

        $person = $this->personPayload($cloudEvent);
        $personId = $person['person_id'];
        $personVersion = $person['person_version'];
        $watermark = DB::table('identity_person_event_watermarks')->where('person_id', $personId)->value('last_person_version');
        if (is_numeric($watermark) && $personVersion <= (int) $watermark) {
            return $this->recordStale($cloudEvent);
        }
        if ($cloudEvent['type'] === self::PERSON_UPDATED
            && $person['status'] !== $cloudEvent['data']['previous_status']) {
            return $this->recordStale($cloudEvent);
        }

        return DB::transaction(function () use ($cloudEvent, $person, $personId, $personVersion): bool {
            if ($this->inboxContains($cloudEvent['id'])) {
                return false;
            }
            if (! $this->insertInbox($cloudEvent)) {
                return false;
            }

            DB::table('identity_person_event_watermarks')->insertOrIgnore([
                'person_id' => $personId,
                'last_person_version' => 0,
                'last_event_id' => $cloudEvent['id'],
                'last_event_type' => $cloudEvent['type'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $watermark = DB::table('identity_person_event_watermarks')->where('person_id', $personId)->lockForUpdate()->first();
            if (! $watermark instanceof stdClass) {
                throw new InvalidArgumentException('identity_watermark_unavailable');
            }
            if ($personVersion <= (int) $watermark->last_person_version) {
                return false;
            }

            $validation = $this->people->validate($personId, $personVersion);
            $reference = $validation['reference'];
            if ($validation['state'] === 'missing' || $reference === null || $reference['person_id'] !== $personId) {
                throw new InvalidArgumentException('person_reference_unavailable');
            }
            if ($validation['state'] !== 'current') {
                if ($reference['person_version'] > $personVersion) {
                    return false;
                }

                throw new InvalidArgumentException('person_reference_stale');
            }
            if ($cloudEvent['type'] === self::ACCESS_STATUS && $reference['status'] !== $cloudEvent['data']['access_status']) {
                throw new InvalidArgumentException('person_reference_stale');
            }
            if ($cloudEvent['type'] === self::PERSON_UPDATED && $reference['status'] !== $person['status']) {
                throw new InvalidArgumentException('person_reference_stale');
            }
            if ($cloudEvent['type'] === self::PROVISIONING) {
                $expectedStatus = $reference['status'] === 'active' ? 'pending' : 'disabled';
                if ($cloudEvent['data']['requested_account_status'] !== $expectedStatus) {
                    throw new InvalidArgumentException('person_reference_stale');
                }
            }

            if ($cloudEvent['type'] === self::PROVISIONING) {
                DB::table('identity_person_provisioning')->updateOrInsert(
                    ['person_id' => $personId],
                    [
                        'person_version' => $personVersion,
                        'requested_account_status' => $cloudEvent['data']['requested_account_status'],
                        'last_event_id' => $cloudEvent['id'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }

            $account = DB::table('identity_person_account_claims')
                ->join('users', 'users.id', '=', 'identity_person_account_claims.account_id')
                ->where('identity_person_account_claims.person_id', $personId)
                ->select('users.*')
                ->lockForUpdate()
                ->first();
            if ($account instanceof stdClass) {
                $disable = ($cloudEvent['type'] === self::ACCESS_STATUS
                        && in_array($cloudEvent['data']['access_status'], ['suspended', 'left'], true))
                    || ($cloudEvent['type'] === self::PROVISIONING
                        && $cloudEvent['data']['requested_account_status'] === 'disabled');
                $status = $disable ? 'disabled' : (string) $account->status;
                $version = (int) $account->lock_version + 1;
                $updated = DB::table('users')
                    ->where('id', $account->id)
                    ->where('lock_version', $account->lock_version)
                    ->update([
                        'person_version' => $personVersion,
                        'display_name_ar' => $reference['display_name_ar'],
                        'display_name_en' => $reference['display_name_en'],
                        'status' => $status,
                        'lock_version' => $version,
                        'updated_at' => now(),
                    ]);
                if ($updated !== 1) {
                    throw new InvalidArgumentException('identity_account_concurrency_conflict');
                }
                if ($disable) {
                    DB::table('identity_sessions')->where('user_id', $account->id)->whereNull('revoked_at')->update([
                        'revoked_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $this->outbox->insert($this->identityEvent($cloudEvent, $account, $status, $version), (string) $account->id);
            }

            DB::table('identity_person_event_watermarks')->where('person_id', $personId)->update([
                'last_person_version' => $personVersion,
                'last_event_id' => $cloudEvent['id'],
                'last_event_type' => $cloudEvent['type'],
                'updated_at' => now(),
            ]);

            return true;
        });
    }

    /** @param array<string, mixed> $cloudEvent */
    private function recordStale(array $cloudEvent): bool
    {
        return DB::transaction(function () use ($cloudEvent): bool {
            if ($this->inboxContains($cloudEvent['id'])) {
                return false;
            }

            $this->insertInbox($cloudEvent);

            return false;
        });
    }

    private function inboxContains(mixed $eventId): bool
    {
        return DB::table('identity_inbox')->where('event_id', $eventId)->exists();
    }

    /** @param array<string, mixed> $cloudEvent */
    private function insertInbox(array $cloudEvent): bool
    {
        $person = $this->personPayload($cloudEvent);

        try {
            DB::table('identity_inbox')->insert([
                'event_id' => $cloudEvent['id'],
                'event_type' => $cloudEvent['type'],
                'person_id' => $person['person_id'],
                'person_version' => $person['person_version'],
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        } catch (QueryException $exception) {
            if ($this->isDuplicateInboxEvent($exception)) {
                return false;
            }

            throw $exception;
        }
    }

    private function isDuplicateInboxEvent(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = $exception->errorInfo[1] ?? null;

        return $sqlState === '23505'
            || in_array($driverCode, [1062, '1062'], true)
            || (($driverCode === 19 || $driverCode === '19')
                && str_contains(strtolower($exception->getMessage()), 'identity_inbox.event_id'));
    }

    /** @param array<string, mixed> $cloudEvent */
    private function validate(array $cloudEvent): void
    {
        $data = $cloudEvent['data'] ?? null;
        $type = $cloudEvent['type'] ?? null;
        $person = is_array($data)
            ? ($type === self::PERSON_UPDATED ? ($data['person'] ?? null) : $data)
            : null;
        if (($cloudEvent['specversion'] ?? null) !== '1.0'
            || ! in_array($type, [self::PROVISIONING, self::ACCESS_STATUS, self::PERSON_UPDATED], true)
            || ($cloudEvent['source'] ?? null) !== '/organization'
            || ($cloudEvent['datacontenttype'] ?? null) !== 'application/json'
            || ! is_array($data)
            || ! is_array($person)
            || ! is_array($data['access_context'] ?? null)
            || ($data['classification'] ?? null) !== 'confidential'
            || ! $this->isUuidV7($cloudEvent['id'] ?? null)
            || ! $this->isUuidV7($cloudEvent['correlationid'] ?? null)
            || ! $this->isUuidV7($person['person_id'] ?? null)
            || ! is_int($person['person_version'] ?? null)
            || $person['person_version'] < 1
            || ($cloudEvent['subject'] ?? null) !== '/organization/people/'.$person['person_id']
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z\z/', $cloudEvent['time'] ?? '') !== 1) {
            throw new InvalidArgumentException('Unsupported Organization Person CloudEvent.');
        }
        $this->validatedAccessContext($data['access_context'], $cloudEvent['correlationid']);
        if ($type === self::PROVISIONING
            && ! in_array($data['requested_account_status'] ?? null, ['pending', 'disabled'], true)) {
            throw new InvalidArgumentException('Invalid provisioning request.');
        }
        if ($type === self::ACCESS_STATUS
            && ! in_array($data['access_status'] ?? null, ['active', 'suspended', 'left'], true)) {
            throw new InvalidArgumentException('Invalid Person access status.');
        }
        if ($type === self::PERSON_UPDATED
            && (! in_array($person['status'] ?? null, ['active', 'suspended', 'left'], true)
                || ! in_array($data['previous_status'] ?? null, ['active', 'suspended', 'left'], true))) {
            throw new InvalidArgumentException('Invalid Person update.');
        }
    }

    /** @param array<string, mixed> $cloudEvent @return array<string, mixed> */
    private function personPayload(array $cloudEvent): array
    {
        return $cloudEvent['type'] === self::PERSON_UPDATED
            ? $cloudEvent['data']['person']
            : $cloudEvent['data'];
    }

    /** @param array<string, mixed> $sourceEvent @return array<string, mixed> */
    private function identityEvent(array $sourceEvent, stdClass $account, string $status, int $version): array
    {
        return [
            'specversion' => '1.0',
            'id' => Str::uuid7()->toString(),
            'source' => '/identity',
            'type' => 'com.cluster.identity.useraccountchanged.v1',
            'subject' => '/identity/accounts/'.$account->id,
            'time' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'datacontenttype' => 'application/json',
            'correlationid' => $sourceEvent['correlationid'],
            'data' => [
                'account_id' => $account->id,
                'person_id' => $account->person_id,
                'person_version' => $this->personPayload($sourceEvent)['person_version'],
                'status' => $status,
                'action' => 'reconcile-person',
                'lock_version' => $version,
                'access_context' => $this->validatedAccessContext($sourceEvent['data']['access_context'], $sourceEvent['correlationid']),
                'classification' => 'confidential',
            ],
        ];
    }

    private function isUuidV7(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1;
    }

    /** @return array<string, mixed> */
    private function validatedAccessContext(mixed $context, mixed $correlationId): array
    {
        if (! is_array($context)
            || array_diff(array_keys($context), [
                'subject_id',
                'tenant_id',
                'organization_unit_ids',
                'roles',
                'clearance',
                'break_glass',
                'correlation_id',
            ]) !== []
            || ! $this->isUuidV7($context['subject_id'] ?? null)
            || ! $this->isUuidV7($context['tenant_id'] ?? null)
            || ! $this->isUuidV7($context['correlation_id'] ?? null)
            || $context['correlation_id'] !== $correlationId
            || ! in_array($context['clearance'] ?? null, ['public', 'internal', 'confidential', 'top_secret'], true)
            || (isset($context['organization_unit_ids']) && ! is_array($context['organization_unit_ids']))
            || (isset($context['roles']) && ! is_array($context['roles']))
            || (isset($context['break_glass']) && ! is_bool($context['break_glass']))
            || array_filter(
                $context['organization_unit_ids'] ?? [],
                fn (mixed $unitId): bool => ! $this->isUuidV7($unitId),
            ) !== []
            || array_filter(
                $context['roles'] ?? [],
                static fn (mixed $role): bool => ! is_string($role) || $role === '' || strlen($role) > 128,
            ) !== []) {
            throw new InvalidArgumentException('Organization access context is invalid.');
        }

        return array_intersect_key($context, array_flip([
            'subject_id',
            'tenant_id',
            'organization_unit_ids',
            'roles',
            'clearance',
            'break_glass',
            'correlation_id',
        ]));
    }
}
