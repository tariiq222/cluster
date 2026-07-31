<?php

namespace Modules\Identity\Features\UserAccount\Handler;

use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Modules\Identity\Domain\UserAccount;
use Modules\Identity\Infrastructure\Outbox\IdentityOutbox;
use Modules\Organization\Contracts\ValidatePersonReference;
use stdClass;
use UnexpectedValueException;

final class UserAccountHandler
{
    public function __construct(
        private readonly IdentityOutbox $outbox,
        private readonly ValidatePersonReference $people,
    ) {}

    /**
     * @param  array{person_id: string, person_version: int, username: string}  $input
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @param  Closure(array<string, mixed>): array<string, mixed>  $eventFactory
     * @return array{request_hash_matches: bool, account: array<string, mixed>, lock_version: int}
     */
    public function create(string $accountId, array $input, array $idempotency, Closure $eventFactory): array
    {
        return DB::transaction(function () use ($accountId, $input, $idempotency, $eventFactory): array {
            $existing = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
            if ($existing instanceof stdClass) {
                return $this->replay($existing, $idempotency['request_hash']);
            }
            $reference = $this->validatedReference($input['person_id'], $input['person_version']);

            $username = UserAccount::normalizeUsername($input['username']);
            if (DB::table('users')->where('username', $username)->exists()) {
                throw new DomainException('username_already_exists');
            }
            if (DB::table('identity_person_account_claims')->where('person_id', $input['person_id'])->exists()) {
                throw new DomainException('person_account_already_exists');
            }

            $claimed = DB::table('identity_idempotency_keys')->insertOrIgnore([
                'principal_id' => $idempotency['principal_id'],
                'operation' => $idempotency['operation'],
                'idempotency_key_hash' => $idempotency['key_hash'],
                'request_hash' => $idempotency['request_hash'],
                'resource_type' => 'account',
                'resource_id' => $accountId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if (! $claimed) {
                $concurrent = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
                if (! $concurrent instanceof stdClass) {
                    throw new UnexpectedValueException('The Identity idempotency claim could not be resolved.');
                }

                return $this->replay($concurrent, $idempotency['request_hash']);
            }

            $account = UserAccount::create($accountId, $input['username'], $reference)->toArray();
            DB::table('users')->insert([
                'id' => $account['id'],
                'username' => $account['username'],
                'person_id' => $account['person_id'],
                'person_version' => $account['person_version'],
                'display_name_ar' => $account['display_name_ar'],
                'display_name_en' => $account['display_name_en'],
                'status' => $account['status'],
                'must_change_password' => $account['must_change_password'],
                'password_version' => $account['password_version'],
                'last_login_at' => null,
                'failed_login_count' => 0,
                'locked_until' => null,
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $personClaimed = DB::table('identity_person_account_claims')->insertOrIgnore([
                'person_id' => $account['person_id'],
                'account_id' => $account['id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if (! $personClaimed) {
                throw new DomainException('person_account_already_exists');
            }
            $this->storeReplay($idempotency, $account, 1);
            $this->outbox->insert($eventFactory($account), $accountId);

            return ['request_hash_matches' => true, 'account' => $account, 'lock_version' => 1];
        });
    }

    /** @return array{account: array<string, mixed>, lock_version: int}|null */
    public function find(string $accountId): ?array
    {
        $row = $this->accountQuery()->where('id', $accountId)->first();

        return $row instanceof stdClass
            ? ['account' => $this->serialize($row), 'lock_version' => (int) $row->lock_version]
            : null;
    }

    /**
     * @param  array{user_id: string, facility_id: string}  $principal
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function list(array $principal, ?string $cursor, int $limit): array
    {
        $afterId = $cursor === null ? null : $this->decodeCursor($cursor, $principal, $limit);
        $query = $this->accountQuery()->orderBy('id');
        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }
        $rows = $query->limit($limit + 1)->get()->all();
        $hasNextPage = count($rows) > $limit;
        if ($hasNextPage) {
            array_pop($rows);
        }
        $items = array_map(fn (stdClass $row): array => $this->serialize($row), $rows);

        return [
            'items' => $items,
            'next_cursor' => $hasNextPage
                ? $this->encodeCursor((string) $rows[array_key_last($rows)]->id, $principal, $limit)
                : null,
        ];
    }

    /**
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @param  Closure(array<string, mixed>, string, string|null, int): array<string, mixed>  $eventFactory
     * @return array{request_hash_matches: bool, account: array<string, mixed>, lock_version: int}
     */
    public function transition(
        string $accountId,
        string $action,
        int $expectedVersion,
        ?string $reason,
        array $idempotency,
        Closure $eventFactory,
    ): array {
        return DB::transaction(function () use ($accountId, $action, $expectedVersion, $reason, $idempotency, $eventFactory): array {
            $existing = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
            if ($existing instanceof stdClass) {
                return $this->replay($existing, $idempotency['request_hash']);
            }
            if (in_array($action, ['activate', 'unlock'], true)) {
                $personLink = DB::table('users')->where('id', $accountId)->first(['person_id', 'person_version']);
                if (! $personLink instanceof stdClass) {
                    throw new DomainException('account_not_found');
                }
                $this->validatedReference((string) $personLink->person_id, (int) $personLink->person_version);
            }
            $row = DB::table('users')->where('id', $accountId)->lockForUpdate()->first();
            if (! $row instanceof stdClass) {
                throw new DomainException('account_not_found');
            }
            if ((int) $row->lock_version !== $expectedVersion) {
                throw new DomainException('precondition_failed');
            }

            $status = (string) $row->status;
            $mustChangePassword = (bool) $row->must_change_password;
            $mfaRequired = (bool) $row->mfa_required;
            $lockedUntil = $row->locked_until;
            $revokeSessions = false;
            if ($action === 'activate') {
                // Pending accounts have no credential yet; activating them
                // directly would produce an account that can never log in.
                // They must be activated through the activation token flow
                // (IssueActivationToken), which creates the credential.
                if (! in_array($status, ['disabled'], true)) {
                    throw new DomainException('invalid_account_transition');
                }
                $status = 'active';
            } elseif ($action === 'unlock') {
                if ($status !== 'locked') {
                    throw new DomainException('invalid_account_transition');
                }
                $status = 'active';
                $lockedUntil = null;
            } elseif ($action === 'disable') {
                if (! in_array($status, ['pending', 'active', 'locked'], true)) {
                    throw new DomainException('invalid_account_transition');
                }
                $status = 'disabled';
                $revokeSessions = true;
            } elseif ($action === 'archive') {
                if ($status === 'archived') {
                    throw new DomainException('invalid_account_transition');
                }
                $status = 'archived';
                $revokeSessions = true;
            } elseif ($action === 'revoke-sessions') {
                if ($status === 'archived') {
                    throw new DomainException('invalid_account_transition');
                }
                $revokeSessions = true;
            } elseif ($action === 'force-password-change') {
                if ($status === 'archived') {
                    throw new DomainException('invalid_account_transition');
                }
                $mustChangePassword = true;
                $revokeSessions = true;
            } elseif ($action === 'require-mfa') {
                if ($status === 'archived') {
                    throw new DomainException('invalid_account_transition');
                }
                $mfaRequired = true;
                $revokeSessions = true;
            } elseif ($action === 'optional-mfa') {
                if ($status === 'archived') {
                    throw new DomainException('invalid_account_transition');
                }
                $mfaRequired = false;
            } elseif ($action === 'reset-credential') {
                // A legacy or unusable credential hash must not strand the
                // account: clearing it returns the account to pending so the
                // existing activation token flow can re-issue a credential.
                if ($status === 'archived' || ! DB::table('credentials')->where('user_id', $accountId)->exists()) {
                    throw new DomainException('invalid_account_transition');
                }
                DB::table('credentials')->where('user_id', $accountId)->delete();
                $status = 'pending';
                $mustChangePassword = true;
                $lockedUntil = null;
                $revokeSessions = true;
            } else {
                throw new InvalidArgumentException('Unsupported account action.');
            }

            $claimed = DB::table('identity_idempotency_keys')->insertOrIgnore([
                'principal_id' => $idempotency['principal_id'],
                'operation' => $idempotency['operation'],
                'idempotency_key_hash' => $idempotency['key_hash'],
                'request_hash' => $idempotency['request_hash'],
                'resource_type' => 'account',
                'resource_id' => $accountId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if (! $claimed) {
                $concurrent = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
                if (! $concurrent instanceof stdClass) {
                    throw new UnexpectedValueException('The Identity action claim could not be resolved.');
                }

                return $this->replay($concurrent, $idempotency['request_hash']);
            }

            $version = (int) $row->lock_version + 1;
            $passwordVersion = (int) $row->password_version + (in_array($action, ['force-password-change', 'reset-credential'], true) ? 1 : 0);
            $updated = DB::table('users')->where('id', $accountId)->where('lock_version', $expectedVersion)->update([
                'status' => $status,
                'must_change_password' => $mustChangePassword,
                'mfa_required' => $mfaRequired,
                'password_version' => $passwordVersion,
                'locked_until' => $lockedUntil,
                'failed_login_count' => in_array($action, ['unlock', 'reset-credential'], true) ? 0 : $row->failed_login_count,
                'lock_version' => $version,
                'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                throw new DomainException('precondition_failed');
            }
            if ($revokeSessions) {
                DB::table('identity_sessions')->where('user_id', $accountId)->whereNull('revoked_at')->update([
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            if ($action === 'archive') {
                DB::table('identity_person_account_claims')->where('account_id', $accountId)->delete();
            }

            $account = $this->serializeValues($row, $status, $mustChangePassword, $mfaRequired, $passwordVersion, $lockedUntil);
            $this->storeReplay($idempotency, $account, $version);
            $this->outbox->insert($eventFactory($account, $action, $reason, $version), $accountId);

            return ['request_hash_matches' => true, 'account' => $account, 'lock_version' => $version];
        });
    }

    /** @param array{principal_id: string, operation: string, key_hash: string, request_hash: string} $idempotency */
    public function findReplay(array $idempotency): ?array
    {
        $row = $this->idempotencyQuery($idempotency)->first();

        return $row instanceof stdClass && is_string($row->response_payload) && is_numeric($row->response_version)
            ? $this->replay($row, $idempotency['request_hash'])
            : null;
    }

    /** @return array{person_id: string, person_version: int, status: string, display_name_ar: string, display_name_en: string|null} */
    private function validatedReference(string $personId, int $personVersion): array
    {
        $validation = $this->people->validate($personId, $personVersion);
        $reference = $validation['reference'];
        if ($validation['state'] === 'missing' || $reference === null || $reference['person_id'] !== $personId) {
            throw new DomainException('person_reference_unavailable');
        }
        if ($validation['state'] !== 'current' || $reference['person_version'] !== $personVersion) {
            throw new DomainException('person_reference_stale');
        }
        if ($reference['status'] !== 'active') {
            throw new DomainException('person_reference_inactive');
        }

        return $reference;
    }

    private function accountQuery(): mixed
    {
        return DB::table('users')->select([
            'id', 'username', 'person_id', 'person_version', 'status', 'must_change_password', 'mfa_required',
            'password_version', 'locked_until', 'display_name_ar', 'display_name_en', 'lock_version',
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'username' => $row->username,
            'person_id' => $row->person_id,
            'person_version' => (int) $row->person_version,
            'status' => $row->status,
            'must_change_password' => (bool) $row->must_change_password,
            'mfa_required' => (bool) $row->mfa_required,
            'password_version' => (int) $row->password_version,
            'locked_until' => $this->timestamp($row->locked_until),
            'display_name_ar' => $row->display_name_ar,
            'display_name_en' => $row->display_name_en,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeValues(stdClass $row, string $status, bool $mustChangePassword, bool $mfaRequired, int $passwordVersion, mixed $lockedUntil): array
    {
        return [
            'id' => $row->id,
            'username' => $row->username,
            'person_id' => $row->person_id,
            'person_version' => (int) $row->person_version,
            'status' => $status,
            'must_change_password' => $mustChangePassword,
            'mfa_required' => $mfaRequired,
            'password_version' => $passwordVersion,
            'locked_until' => $this->timestamp($lockedUntil),
            'display_name_ar' => $row->display_name_ar,
            'display_name_en' => $row->display_name_en,
        ];
    }

    private function timestamp(mixed $value): ?string
    {
        return is_string($value) ? CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }

    /** @param array{principal_id: string, operation: string, key_hash: string, request_hash: string} $idempotency */
    private function idempotencyQuery(array $idempotency): mixed
    {
        return DB::table('identity_idempotency_keys')
            ->where('principal_id', $idempotency['principal_id'])
            ->where('operation', $idempotency['operation'])
            ->where('idempotency_key_hash', $idempotency['key_hash']);
    }

    /** @param array{principal_id: string, operation: string, key_hash: string, request_hash: string} $idempotency */
    private function storeReplay(array $idempotency, array $account, int $version): void
    {
        $snapshot = Crypt::encryptString(json_encode($account, JSON_THROW_ON_ERROR));
        $this->idempotencyQuery($idempotency)->update([
            'response_payload' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'response_version' => $version,
            'updated_at' => now(),
        ]);
    }

    /** @return array{request_hash_matches: bool, account: array<string, mixed>, lock_version: int} */
    private function replay(stdClass $row, string $requestHash): array
    {
        if (! is_string($row->response_payload) || ! is_numeric($row->response_version)) {
            throw new UnexpectedValueException('Stored Identity idempotency state is incomplete.');
        }
        try {
            $encrypted = json_decode($row->response_payload, true, 4, JSON_THROW_ON_ERROR);
            if (! is_string($encrypted)) {
                throw new UnexpectedValueException('Stored Identity replay response is invalid.');
            }
            $account = json_decode(Crypt::decryptString($encrypted), true, 32, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new UnexpectedValueException('Stored Identity replay response is invalid.');
        }
        if (! is_array($account)) {
            throw new UnexpectedValueException('Stored Identity replay response is invalid.');
        }

        return [
            'request_hash_matches' => is_string($row->request_hash) && hash_equals($row->request_hash, $requestHash),
            'account' => $account,
            'lock_version' => (int) $row->response_version,
        ];
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function encodeCursor(string $accountId, array $principal, int $limit): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 1,
            'resource' => 'identity_account',
            'after_id' => $accountId,
            'limit' => $limit,
            'principal_id' => $principal['user_id'],
            'facility_id' => $principal['facility_id'],
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function decodeCursor(string $cursor, array $principal, int $limit): string
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new InvalidArgumentException('The account cursor is invalid.');
        }
        if (! is_array($payload)
            || ($payload['version'] ?? null) !== 1
            || ($payload['resource'] ?? null) !== 'identity_account'
            || ($payload['limit'] ?? null) !== $limit
            || ! is_string($payload['principal_id'] ?? null)
            || ! hash_equals($principal['user_id'], $payload['principal_id'])
            || ! is_string($payload['facility_id'] ?? null)
            || ! hash_equals($principal['facility_id'], $payload['facility_id'])
            || ! is_string($payload['after_id'] ?? null)) {
            throw new InvalidArgumentException('The account cursor is invalid.');
        }

        return $payload['after_id'];
    }
}
