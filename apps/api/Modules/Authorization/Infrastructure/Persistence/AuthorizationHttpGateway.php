<?php

namespace Modules\Authorization\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Authorization\Contracts\CapabilityCatalog;
use Modules\Authorization\Domain\Capability;
use Modules\Authorization\Domain\Delegation;
use Modules\Authorization\Domain\ExplicitDeny;
use Modules\Authorization\Domain\Role;
use Modules\Authorization\Domain\RoleAssignment;
use Modules\Authorization\Domain\UuidV7;
use Modules\Organization\Contracts\ResolveScopeDescendants;

final class AuthorizationHttpGateway
{
    /** @var array<string, string> */
    private const TABLES = [
        'roles' => 'roles',
        'capabilities' => 'capabilities',
        'role-capabilities' => 'role_capabilities',
        'role-assignments' => 'role_assignments',
        'delegations' => 'delegations',
        'explicit-denies' => 'explicit_denies',
        'classification-policies' => 'classification_policies',
        'field-access-templates' => 'field_access_templates',
    ];

    /** @var list<string> */
    private const SCOPE_TYPES = ['cluster', 'facility', 'unit', 'record_set'];

    public function __construct(
        private readonly ValidateDelegationAuthority $delegationAuthority,
        private readonly ValidateGrantAuthority $grantAuthority,
        private readonly ResolveScopeDescendants $descendants,
    ) {}

    /** @return list<string> */
    public static function resources(): array
    {
        return array_keys(self::TABLES);
    }

    public function table(string $resource): ?string
    {
        return self::TABLES[$resource] ?? null;
    }

    /** @return array{items: list<array<string,mixed>>, next_cursor: string|null} */
    public function list(string $resource, ?string $cursor, int $limit, string $actorUserId): array
    {
        $table = $this->requireTable($resource);
        if ($resource === 'role-capabilities') {
            return $this->listRoleCapabilities($table, $cursor, $limit, $actorUserId);
        }
        $query = DB::table($table);
        $this->applyActorScope($query, $resource, $actorUserId);
        $query->orderBy('id');
        if ($cursor !== null) {
            $cursorId = $this->decodeCursor($cursor);
            $query->where('id', '>', $cursorId);
        }
        $rows = $query->limit($limit + 1)->get();
        $nextCursor = null;
        if ($rows->count() > $limit) {
            $rows->pop();
            $last = $rows->last();
            $nextCursor = $last === null ? null : $this->encodeCursor((string) $last->id);
        }

        return [
            'items' => $rows->map(fn (object $row): array => $this->serialize($resource, $row))->values()->all(),
            'next_cursor' => $nextCursor,
        ];
    }

    /** @return array{items: list<array<string,mixed>>, next_cursor: string|null} */
    private function listRoleCapabilities(string $table, ?string $cursor, int $limit, string $actorUserId): array
    {
        $query = DB::table($table);
        $this->applyActorScope($query, 'role-capabilities', $actorUserId);
        $query->orderBy('role_id')->orderBy('capability_id');
        if ($cursor !== null) {
            [$roleId, $capabilityId] = $this->splitRoleCapabilityId($this->decodeCursor($cursor));
            $query->where(function ($q) use ($roleId, $capabilityId): void {
                $q->where('role_id', '>', $roleId)
                    ->orWhere(function ($q2) use ($roleId, $capabilityId): void {
                        $q2->where('role_id', $roleId)->where('capability_id', '>', $capabilityId);
                    });
            });
        }
        $rows = $query->limit($limit + 1)->get();
        $nextCursor = null;
        if ($rows->count() > $limit) {
            $rows->pop();
            $last = $rows->last();
            $nextCursor = $last === null ? null : $this->encodeCursor($last->role_id.':'.$last->capability_id);
        }

        return [
            'items' => $rows->map(fn (object $row): array => $this->serialize('role-capabilities', $row))->values()->all(),
            'next_cursor' => $nextCursor,
        ];
    }

    /** @return array<string,mixed>|null */
    public function find(string $resource, string $id, ?string $actorUserId = null): ?array
    {
        $table = $this->requireTable($resource);
        if ($resource === 'role-capabilities') {
            [$roleId, $capabilityId] = $this->splitRoleCapabilityId($id);
            $query = DB::table($table)->where('role_id', $roleId)->where('capability_id', $capabilityId);
            if ($actorUserId !== null) {
                $this->applyActorScope($query, $resource, $actorUserId);
            }
            $row = $query->first();

            return $row === null ? null : $this->serialize($resource, $row);
        }
        $query = DB::table($table)->where('id', $id);
        if ($actorUserId !== null) {
            $this->applyActorScope($query, $resource, $actorUserId);
        }
        $row = $query->first();

        return $row === null ? null : $this->serialize($resource, $row);
    }

    /** @param array<string,mixed> $input */
    /** @return array<string,mixed> */
    public function create(string $resource, array $input, string $principalId): array
    {
        if ($resource === 'delegations' && isset($input['delegator_user_id']) && ! hash_equals($principalId, (string) $input['delegator_user_id'])) {
            throw new InvalidArgumentException('delegation_actor_spoofed');
        }
        $table = $this->requireTable($resource);
        $id = Str::uuid7()->toString();
        $now = now()->utc()->format('Y-m-d H:i:s.v');
        $row = match ($resource) {
            'roles' => $this->role($id, $input, $now),
            'capabilities' => $this->capability($id, $input, $now),
            'role-capabilities' => $this->roleCapability($input, $now),
            'role-assignments' => $this->roleAssignment($id, $input, $principalId, $now),
            'delegations' => $this->delegation($id, $input, $principalId, $now),
            'explicit-denies' => $this->explicitDeny($id, $input, $principalId, $now),
            'classification-policies' => $this->classificationPolicy($id, $input, $now),
            'field-access-templates' => $this->fieldTemplate($id, $input, $now),
            default => throw new InvalidArgumentException('authorization_resource_invalid'),
        };
        $capabilityCodes = $row['_capability_codes'] ?? null;
        unset($row['_capability_codes']);
        if ($resource === 'role-capabilities') {
            $existing = DB::table($table)
                ->where('role_id', $row['role_id'])
                ->where('capability_id', $row['capability_id'])
                ->first();
            if ($existing !== null) {
                if ((string) $existing->effect === (string) $row['effect']) {
                    return $this->find($resource, $row['role_id'].':'.$row['capability_id'])
                        ?? throw new InvalidArgumentException('authorization_resource_not_found');
                }
                DB::table($table)
                    ->where('role_id', $row['role_id'])
                    ->where('capability_id', $row['capability_id'])
                    ->update([
                        'effect' => $row['effect'],
                        'lock_version' => ((int) $existing->lock_version) + 1,
                        'updated_at' => $now,
                    ]);
            } else {
                DB::table($table)->insert($row);
            }

            return $this->find($resource, $row['role_id'].':'.$row['capability_id']) ?? throw new InvalidArgumentException('authorization_resource_not_found');
        }
        if (in_array($resource, ['classification-policies', 'field-access-templates'], true)) {
            unset($row['id']);
            $id = (string) DB::table($table)->insertGetId($row);
        } else {
            DB::table($table)->insert($row);
        }

        if ($resource === 'delegations' && is_array($capabilityCodes)) {
            DB::table('delegation_capabilities')->insert(array_map(
                static fn (string $code): array => ['delegation_id' => $id, 'capability_code' => $code],
                $capabilityCodes,
            ));
        }

        return $this->find($resource, $id) ?? throw new InvalidArgumentException('authorization_resource_not_found');
    }

    /** @param array<string,mixed> $patch */
    /** @return array<string,mixed>|null */
    public function update(string $resource, string $id, array $patch, int $expectedVersion, string $actorUserId): ?array
    {
        $table = $this->requireTable($resource);
        $changes = $this->normalisePatch($resource, $patch);
        if ($changes === []) {
            throw new InvalidArgumentException('authorization_patch_empty');
        }
        if ($resource === 'role-capabilities') {
            [$roleId, $capabilityId] = $this->splitRoleCapabilityId($id);
            $changes['lock_version'] = $expectedVersion + 1;
            $changes['updated_at'] = now()->utc()->format('Y-m-d H:i:s.v');
            $query = DB::table($table)->where('role_id', $roleId)->where('capability_id', $capabilityId)->where('lock_version', $expectedVersion);
            $this->applyActorScope($query, $resource, $actorUserId);
            $updated = $query->update($changes);
            if ($updated === 0) {
                $existsQuery = DB::table($table)->where('role_id', $roleId)->where('capability_id', $capabilityId);
                $this->applyActorScope($existsQuery, $resource, $actorUserId);
                $exists = $existsQuery->exists();
                if (! $exists) {
                    return null;
                }
                throw new InvalidArgumentException('authorization_precondition_failed');
            }

            return $this->find($resource, $id, $actorUserId);
        }
        $changes['lock_version'] = $expectedVersion + 1;
        $changes['updated_at'] = now()->utc()->format('Y-m-d H:i:s.v');
        $query = DB::table($table)->where('id', $id)->where('lock_version', $expectedVersion);
        $this->applyActorScope($query, $resource, $actorUserId);
        $updated = $query->update($changes);
        if ($updated === 0) {
            $existsQuery = DB::table($table)->where('id', $id);
            $this->applyActorScope($existsQuery, $resource, $actorUserId);
            $exists = $existsQuery->exists();
            if (! $exists) {
                return null;
            }
            throw new InvalidArgumentException('authorization_precondition_failed');
        }

        return $this->find($resource, $id, $actorUserId);
    }

    /** @return array<string,mixed>|null */
    public function transition(string $resource, string $id, string $action, int $expectedVersion, string $actorUserId): ?array
    {
        if (! in_array($action, ['activate', 'revoke', 'expire', 'publish'], true)) {
            throw new InvalidArgumentException('authorization_action_invalid');
        }
        if ($resource === 'roles' || $resource === 'capabilities') {
            throw new InvalidArgumentException('authorization_action_unsupported');
        }
        if ($resource === 'role-capabilities') {
            if ($action !== 'revoke') {
                throw new InvalidArgumentException('authorization_action_unsupported');
            }
            [$roleId, $capabilityId] = $this->splitRoleCapabilityId($id);
            $query = DB::table('role_capabilities')->where('role_id', $roleId)->where('capability_id', $capabilityId)->where('lock_version', $expectedVersion);
            $this->applyActorScope($query, $resource, $actorUserId);
            $deleted = $query->delete();
            if ($deleted === 0) {
                $existsQuery = DB::table('role_capabilities')->where('role_id', $roleId)->where('capability_id', $capabilityId);
                $this->applyActorScope($existsQuery, $resource, $actorUserId);
                if (! $existsQuery->exists()) {
                    return null;
                }
                throw new InvalidArgumentException('authorization_precondition_failed');
            }

            return ['id' => $id, 'role_id' => $roleId, 'capability_id' => $capabilityId, 'status' => 'revoked', 'resource_type' => 'role_capability', 'lock_version' => $expectedVersion + 1];
        }
        if ($resource === 'explicit-denies') {
            if (! in_array($action, ['revoke', 'expire'], true)) {
                throw new InvalidArgumentException('authorization_action_unsupported');
            }

            return $this->update($resource, $id, ['expires_at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z')], $expectedVersion, $actorUserId);
        }
        $status = match ($action) {
            'activate' => 'active',
            'revoke' => 'revoked',
            'expire' => 'expired',
            'publish' => 'published',
        };

        return $this->update($resource, $id, ['status' => $status], $expectedVersion, $actorUserId);
    }

    /** @param array<string,mixed> $input */
    /** @return array<string,mixed> */
    private function role(string $id, array $input, string $now): array
    {
        $role = Role::define(
            $id,
            (string) ($input['code'] ?? ''),
            (string) ($input['name_ar'] ?? $input['name'] ?? ''),
            isset($input['name_en']) ? (string) $input['name_en'] : null,
            (string) ($input['role_type'] ?? 'custom'),
            (bool) ($input['is_system_role'] ?? false),
        )->toArray();

        return [...$role, 'created_at' => $now, 'updated_at' => $now, 'lock_version' => 1];
    }

    /** @param array<string,mixed> $input */
    /** @return array<string,mixed> */
    private function capability(string $id, array $input, string $now): array
    {
        $code = (string) ($input['capability_code'] ?? $input['code'] ?? '');
        if (! CapabilityCatalog::supports($code)) {
            throw new InvalidArgumentException('capability_code_not_in_catalog');
        }
        $module = (string) ($input['module_code'] ?? explode('.', $code, 2)[0]);
        $action = (string) ($input['action'] ?? (strrchr($code, '.') !== false ? substr((string) strrchr($code, '.'), 1) : 'read'));
        $capability = Capability::define($id, $module, $code, $action, (string) ($input['sensitivity'] ?? 'normal'))->toArray();

        return [...$capability, 'created_at' => $now, 'updated_at' => $now, 'lock_version' => 1];
    }

    /** @param array<string,mixed> $input */
    /** @return array<string,mixed> */
    private function roleCapability(array $input, string $now): array
    {
        $roleId = (string) ($input['role_id'] ?? '');
        UuidV7::assert($roleId, 'Role capability role id');
        if (! DB::table('roles')->where('id', $roleId)->exists()) {
            throw new InvalidArgumentException('authorization_role_not_found');
        }

        $capabilityId = null;
        if (is_string($input['capability_id'] ?? null) && $input['capability_id'] !== '') {
            $capabilityId = $input['capability_id'];
            UuidV7::assert($capabilityId, 'Role capability capability id');
            if (! DB::table('capabilities')->where('id', $capabilityId)->exists()) {
                throw new InvalidArgumentException('authorization_capability_not_found');
            }
        } else {
            $code = (string) ($input['capability_code'] ?? $input['code'] ?? '');
            if (! CapabilityCatalog::supports($code)) {
                throw new InvalidArgumentException('capability_code_not_in_catalog');
            }
            $capabilityId = DB::table('capabilities')->where('capability_code', $code)->value('id');
            if (! is_string($capabilityId)) {
                throw new InvalidArgumentException('authorization_capability_not_found');
            }
        }

        $effect = (string) ($input['effect'] ?? 'allow');
        if (! in_array($effect, ['allow', 'deny'], true)) {
            throw new InvalidArgumentException('authorization_effect_invalid');
        }

        return ['role_id' => $roleId, 'capability_id' => $capabilityId, 'effect' => $effect, 'created_at' => $now, 'updated_at' => $now, 'lock_version' => 1];
    }

    /** @param array<string,mixed> $input */
    /** @return array<string,mixed> */
    private function explicitDeny(string $id, array $input, string $principalId, string $now): array
    {
        $code = (string) ($input['capability_code'] ?? '');
        if (! CapabilityCatalog::supports($code)) {
            throw new InvalidArgumentException('capability_code_not_in_catalog');
        }
        $userId = isset($input['user_id']) ? (string) $input['user_id'] : null;
        $organizationUnitId = isset($input['organization_unit_id']) ? (string) $input['organization_unit_id'] : null;
        if ($userId === null && $organizationUnitId === null) {
            throw new InvalidArgumentException('explicit_deny_subject_required');
        }
        if ($userId !== null) {
            UuidV7::assert($userId, 'Explicit deny user id');
        }
        if ($organizationUnitId !== null) {
            UuidV7::assert($organizationUnitId, 'Explicit deny organization unit id');
        }
        $classification = isset($input['classification']) ? (string) $input['classification'] : null;
        if ($classification !== null && ! in_array($classification, ['public', 'internal', 'confidential', 'top_secret'], true)) {
            throw new InvalidArgumentException('explicit_deny_classification_invalid');
        }
        $resourcePattern = isset($input['resource_pattern']) ? (string) $input['resource_pattern'] : null;
        if ($resourcePattern !== null && ! ExplicitDeny::isValidResourcePattern($resourcePattern)) {
            throw new InvalidArgumentException('explicit_deny_resource_pattern_invalid');
        }
        $reason = (string) ($input['reason'] ?? '');
        if (trim($reason) === '' || mb_strlen($reason) > 2000) {
            throw new InvalidArgumentException('explicit_deny_reason_required');
        }
        $issuedAt = isset($input['issued_at']) ? $this->domainUtc((string) $input['issued_at']) : $this->domainUtc(now()->utc()->format('Y-m-d\TH:i:s.v\Z'));
        $expiresAt = isset($input['expires_at']) ? $this->domainUtc((string) $input['expires_at']) : null;
        if ($expiresAt !== null && $expiresAt <= $issuedAt) {
            throw new InvalidArgumentException('explicit_deny_window_invalid');
        }

        return [
            'id' => $id,
            'user_id' => $userId,
            'capability_code' => $code,
            'classification' => $classification,
            'organization_unit_id' => $organizationUnitId,
            'resource_pattern' => $resourcePattern,
            'reason' => $reason,
            'issued_by_user_id' => $principalId,
            'issued_at' => $this->databaseUtc($issuedAt),
            'expires_at' => $expiresAt === null ? null : $this->databaseUtc($expiresAt),
            'revocable' => (bool) ($input['revocable'] ?? true),
            'created_at' => $now,
            'updated_at' => $now,
            'lock_version' => 1,
        ];
    }

    /** @param array<string,mixed> $input */
    /** @return array<string,mixed> */
    private function roleAssignment(string $id, array $input, string $principalId, string $now): array
    {
        $scopeType = (string) ($input['scope_type'] ?? 'unit');
        if (! in_array($scopeType, self::SCOPE_TYPES, true)) {
            throw new InvalidArgumentException('authorization_scope_type_invalid');
        }
        $scopeId = isset($input['scope_id']) ? (string) $input['scope_id'] : null;
        if ($scopeId === null || trim($scopeId) === '') {
            throw new InvalidArgumentException('authorization_scope_required');
        }
        $roleId = (string) ($input['role_id'] ?? '');
        $capabilityCodes = DB::table('role_capabilities')
            ->join('capabilities', 'capabilities.id', '=', 'role_capabilities.capability_id')
            ->where('role_capabilities.role_id', $roleId)
            ->where('role_capabilities.effect', 'allow')
            ->where('capabilities.status', 'active')
            ->pluck('capabilities.capability_code')->all();
        $startAt = $this->domainUtc((string) ($input['start_at'] ?? ''));
        $endAt = array_key_exists('end_at', $input) && $input['end_at'] !== null ? $this->domainUtc((string) $input['end_at']) : null;
        $this->grantAuthority->assertCovered($principalId, $capabilityCodes, $scopeType, $scopeId, $startAt, $endAt);
        $assignment = RoleAssignment::assign(
            $id,
            (string) ($input['user_id'] ?? $input['subject_user_id'] ?? ''),
            $roleId,
            $scopeId,
            $startAt,
            $endAt,
            $principalId,
        )->toArray();

        return [
            ...$assignment,
            'scope_type' => $scopeType,
            'start_at' => $this->databaseUtc($assignment['start_at']),
            'end_at' => $assignment['end_at'] === null ? null : $this->databaseUtc($assignment['end_at']),
            'created_at' => $now,
            'updated_at' => $now,
            'lock_version' => 1,
        ];
    }

    /** @param array<string,mixed> $input */
    /** @return array<string,mixed> */
    private function delegation(string $id, array $input, string $principalId, string $now): array
    {
        /** @var list<string> $codes */
        $codes = array_values($input['capability_codes'] ?? []);
        foreach ($codes as $code) {
            if (! CapabilityCatalog::supports($code)) {
                throw new InvalidArgumentException('capability_code_not_in_catalog');
            }
        }
        $scopeType = (string) ($input['scope_type'] ?? 'unit');
        if (! in_array($scopeType, self::SCOPE_TYPES, true)) {
            throw new InvalidArgumentException('authorization_scope_type_invalid');
        }
        $scopeId = isset($input['scope_id']) ? (string) $input['scope_id'] : null;
        if ($scopeId === null || trim($scopeId) === '') {
            throw new InvalidArgumentException('authorization_scope_required');
        }
        $startAt = $this->domainUtc((string) ($input['start_at'] ?? ''));
        $endAt = $this->domainUtc((string) ($input['end_at'] ?? ''));
        $delegation = Delegation::create(
            $id,
            $principalId,
            (string) ($input['delegate_user_id'] ?? $input['subject_user_id'] ?? ''),
            (string) ($input['module_code'] ?? ''),
            $codes,
            $scopeId,
            $startAt,
            $endAt,
        )->toArray();

        $this->delegationAuthority->assertCovered(
            $delegation['delegator_user_id'],
            $codes,
            $scopeType,
            $scopeId,
            $delegation['start_at'],
            $delegation['end_at'],
        );

        return [
            ...array_diff_key($delegation, ['capability_codes' => true]),
            'scope_type' => $scopeType,
            'start_at' => $this->databaseUtc($delegation['start_at']),
            'end_at' => $this->databaseUtc($delegation['end_at']),
            '_capability_codes' => $codes,
            'created_at' => $now,
            'updated_at' => $now,
            'lock_version' => 1,
        ];
    }

    /** @param array<string,mixed> $input */
    /** @return array<string,mixed> */
    private function classificationPolicy(string $id, array $input, string $now): array
    {
        $document = is_array($input['policy_document'] ?? null) ? $input['policy_document'] : $input;
        $classification = (string) ($document['classification'] ?? $document['classification_code'] ?? 'internal');
        $row = [
            'classification_code' => $classification,
            'minimum_capability' => (string) ($document['minimum_capability'] ?? $input['code'] ?? ''),
            'export_policy' => (string) ($document['export_policy'] ?? 'deny'),
            'download_policy' => (string) ($document['download_policy'] ?? 'deny'),
            'policy_version' => (string) ($document['policy_version'] ?? 'v1'),
            'is_active' => (bool) ($document['is_active'] ?? true),
            'created_at' => $now,
            'updated_at' => $now,
            'lock_version' => 1,
        ];
        if ($row['minimum_capability'] === '') {
            throw new InvalidArgumentException('classification_policy_capability_required');
        }

        return $row;
    }

    /** @param array<string,mixed> $input */
    /** @return array<string,mixed> */
    private function fieldTemplate(string $id, array $input, string $now): array
    {
        $document = $input['policy_document'] ?? null;
        if (! is_array($document) || $document === []) {
            throw new InvalidArgumentException('field_access_template_policy_required');
        }

        return [
            'field_policy_key' => (string) ($input['field_policy_key'] ?? $input['code'] ?? ''),
            'module_code' => (string) ($input['module_code'] ?? ''),
            'policy_definition' => json_encode($document, JSON_THROW_ON_ERROR),
            'policy_version' => (string) ($input['policy_version'] ?? 'v1'),
            'is_active' => (bool) ($input['is_active'] ?? true),
            'created_at' => $now,
            'updated_at' => $now,
            'lock_version' => 1,
        ];
    }

    /** @param array<string,mixed> $patch */
    /** @return array<string,mixed> */
    private function normalisePatch(string $resource, array $patch): array
    {
        $allowed = match ($resource) {
            'roles' => ['name', 'name_ar', 'name_en', 'role_type', 'status'],
            'capabilities' => ['action', 'sensitivity', 'status'],
            'role-capabilities' => ['effect'],
            'role-assignments' => ['end_at', 'status'],
            'delegations' => ['end_at', 'status'],
            'explicit-denies' => ['expires_at', 'reason', 'classification', 'resource_pattern'],
            'classification-policies' => ['status', 'policy_document', 'is_active', 'export_policy', 'download_policy', 'policy_version'],
            'field-access-templates' => ['status', 'policy_document', 'is_active'],
            default => [],
        };
        if (array_diff(array_keys($patch), $allowed) !== []) {
            throw new InvalidArgumentException('authorization_patch_invalid');
        }
        $changes = $patch;
        if (array_key_exists('effect', $changes) && ! in_array($changes['effect'], ['allow', 'deny'], true)) {
            throw new InvalidArgumentException('authorization_effect_invalid');
        }
        if (array_key_exists('classification', $changes) && $changes['classification'] !== null && ! in_array($changes['classification'], ['public', 'internal', 'confidential', 'top_secret'], true)) {
            throw new InvalidArgumentException('explicit_deny_classification_invalid');
        }
        if (array_key_exists('resource_pattern', $changes) && $changes['resource_pattern'] !== null && ! ExplicitDeny::isValidResourcePattern((string) $changes['resource_pattern'])) {
            throw new InvalidArgumentException('explicit_deny_resource_pattern_invalid');
        }
        if (array_key_exists('name', $changes)) {
            $changes['name_ar'] = $changes['name'];
            unset($changes['name']);
        }
        if (array_key_exists('policy_document', $changes)) {
            $changes[$resource === 'field-access-templates' ? 'policy_definition' : 'minimum_capability'] = $resource === 'field-access-templates'
                ? json_encode($changes['policy_document'], JSON_THROW_ON_ERROR)
                : (string) (($changes['policy_document']['minimum_capability'] ?? ''));
            unset($changes['policy_document']);
        }
        if (array_key_exists('end_at', $changes) && $changes['end_at'] !== null) {
            $changes['end_at'] = $this->databaseUtc($this->domainUtc((string) $changes['end_at']));
        }
        if ($resource === 'explicit-denies' && array_key_exists('expires_at', $changes) && $changes['expires_at'] !== null) {
            $changes['expires_at'] = $this->databaseUtc($this->domainUtc((string) $changes['expires_at']));
        }

        return $changes;
    }

    /** @return array<string,mixed> */
    private function serialize(string $resource, object $row): array
    {
        $values = get_object_vars($row);
        unset($values['created_at'], $values['updated_at']);
        if ($resource === 'role-capabilities') {
            $values['id'] = $values['role_id'].':'.$values['capability_id'];
            $values['capability_code'] = DB::table('capabilities')->where('id', $values['capability_id'])->value('capability_code');
            $values['resource_type'] = 'role_capability';

            return $values;
        }
        $values['resource_type'] = match ($resource) {
            'roles' => 'role',
            'capabilities' => 'capability',
            'role-assignments' => 'role_assignment',
            'delegations' => 'delegation',
            'explicit-denies' => 'explicit_deny',
            'classification-policies' => 'classification_policy',
            'field-access-templates' => 'field_access_template',
            default => throw new InvalidArgumentException('authorization_resource_invalid'),
        };
        unset($values['_capability_codes']);
        if (isset($values['policy_definition']) && is_string($values['policy_definition'])) {
            $values['policy_document'] = json_decode($values['policy_definition'], true);
            unset($values['policy_definition']);
        }
        if ($resource === 'delegations') {
            $values['capability_codes'] = DB::table('delegation_capabilities')
                ->where('delegation_id', $values['id'])
                ->orderBy('capability_code')
                ->pluck('capability_code')->all();
        }

        return $values;
    }

    private function applyActorScope(Builder $query, string $resource, string $actorUserId): void
    {
        $scopes = $this->actorScopes($actorUserId);
        if ($scopes === []) {
            $query->whereRaw('1 = 0');

            return;
        }
        match ($resource) {
            'role-assignments', 'delegations' => $this->applyDirectScopePredicate($query, $scopes),
            'explicit-denies' => $this->applyExplicitDenyScopePredicate($query, $scopes, $actorUserId),
            'roles' => $this->applyRoleScopePredicate($query, $scopes),
            'capabilities' => $this->applyCapabilityScopePredicate($query, $scopes),
            'role-capabilities' => $this->applyRoleCapabilityScopePredicate($query, $scopes),
            'classification-policies', 'field-access-templates' => $this->applyGlobalPolicyScopePredicate($query, $scopes),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** @return list<array{scope_type:string,scope_id:string}> */
    private function actorScopes(string $actorUserId): array
    {
        $now = now()->utc();

        return DB::table('role_assignments')
            ->where('user_id', $actorUserId)->where('status', 'active')
            ->where('start_at', '<=', $now)
            ->where(fn (Builder $query) => $query->whereNull('end_at')->orWhere('end_at', '>', $now))
            ->get(['scope_type', 'scope_id'])->map(static fn (object $row): array => [
                'scope_type' => (string) $row->scope_type, 'scope_id' => (string) $row->scope_id,
            ])->all();
    }

    /** @param list<array{scope_type:string,scope_id:string}> $scopes */
    private function applyDirectScopePredicate(Builder $query, array $scopes): void
    {
        $query->where(function (Builder $outer) use ($scopes): void {
            foreach ($scopes as $scope) {
                $outer->orWhere(function (Builder $candidate) use ($scope): void {
                    $candidate->where('scope_type', $scope['scope_type'])->where('scope_id', $scope['scope_id']);
                    foreach ($this->descendantScopes($scope['scope_type'], $scope['scope_id']) as $descendant) {
                        $candidate->orWhere(fn (Builder $q) => $q->where('scope_type', $descendant['scope_type'])->where('scope_id', $descendant['scope_id']));
                    }
                });
            }
        });
    }

    /** @param list<array{scope_type:string,scope_id:string}> $scopes */
    private function applyExplicitDenyScopePredicate(Builder $query, array $scopes, string $actorUserId): void
    {
        $ids = [];
        foreach ($scopes as $scope) {
            $ids[] = $scope['scope_id'];
            foreach ($this->descendantScopes($scope['scope_type'], $scope['scope_id']) as $descendant) {
                $ids[] = $descendant['scope_id'];
            }
        }
        $query->where(function (Builder $candidate) use ($ids, $actorUserId): void {
            $candidate->whereIn('organization_unit_id', array_values(array_unique($ids)))
                ->orWhere('user_id', $actorUserId)
                ->orWhere('issued_by_user_id', $actorUserId);
        });
    }

    /** @param list<array{scope_type:string,scope_id:string}> $scopes */
    private function applyRoleScopePredicate(Builder $query, array $scopes): void
    {
        $query->whereExists(function (Builder $subquery) use ($scopes): void {
            $subquery->selectRaw('1')->from('role_assignments as scoped_assignments')->whereColumn('scoped_assignments.role_id', 'roles.id');
            $this->applyDirectScopePredicate($subquery, $scopes);
        });
    }

    /** @param list<array{scope_type:string,scope_id:string}> $scopes */
    private function applyCapabilityScopePredicate(Builder $query, array $scopes): void
    {
        $query->whereExists(function (Builder $subquery) use ($scopes): void {
            $subquery->selectRaw('1')->from('role_capabilities as scoped_role_capabilities')
                ->join('role_assignments as scoped_assignments', 'scoped_assignments.role_id', '=', 'scoped_role_capabilities.role_id')
                ->whereColumn('scoped_role_capabilities.capability_id', 'capabilities.id');
            $this->applyDirectScopePredicate($subquery, $scopes);
        });
    }

    /** @param list<array{scope_type:string,scope_id:string}> $scopes */
    private function applyRoleCapabilityScopePredicate(Builder $query, array $scopes): void
    {
        $query->where(function (Builder $candidate) use ($scopes): void {
            $candidate->whereNotExists(function (Builder $subquery): void {
                $subquery->selectRaw('1')->from('role_assignments as any_assignments')
                    ->whereColumn('any_assignments.role_id', 'role_capabilities.role_id')
                    ->where('any_assignments.status', 'active');
            })->orWhere(function (Builder $assigned) use ($scopes): void {
                $assigned->whereExists(function (Builder $subquery) use ($scopes): void {
                    $subquery->selectRaw('1')->from('role_assignments as scoped_assignments')
                        ->whereColumn('scoped_assignments.role_id', 'role_capabilities.role_id')
                        ->where('scoped_assignments.status', 'active');
                    $this->applyDirectScopePredicate($subquery, $scopes);
                })->whereNotExists(function (Builder $subquery) use ($scopes): void {
                    $subquery->selectRaw('1')->from('role_assignments as outside_assignments')
                        ->whereColumn('outside_assignments.role_id', 'role_capabilities.role_id')
                        ->where('outside_assignments.status', 'active')
                        ->whereNot(function (Builder $covered) use ($scopes): void {
                            $this->applyDirectScopePredicate($covered, $scopes);
                        });
                });
            });
        });
    }

    /** @param list<array{scope_type:string,scope_id:string}> $scopes */
    private function applyGlobalPolicyScopePredicate(Builder $query, array $scopes): void
    {
        if (! collect($scopes)->contains(fn (array $scope): bool => $scope['scope_type'] === 'cluster')) {
            $query->whereRaw('1 = 0');
        }
    }

    /** @return list<array{scope_type:string,scope_id:string}> */
    private function descendantScopes(string $scopeType, string $scopeId): array
    {
        return $this->descendants->descendants($scopeType, $scopeId);
    }

    /** @return array{0: string, 1: string} */
    private function splitRoleCapabilityId(string $id): array
    {
        $parts = explode(':', $id);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('authorization_resource_invalid');
        }
        UuidV7::assert($parts[0], 'Role capability role id');
        UuidV7::assert($parts[1], 'Role capability capability id');

        return [$parts[0], $parts[1]];
    }

    private function requireTable(string $resource): string
    {
        return self::TABLES[$resource] ?? throw new InvalidArgumentException('authorization_resource_invalid');
    }

    private function domainUtc(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s.v\Z') !== $value) {
            throw new InvalidArgumentException('authorization_timestamp_invalid');
        }

        return $value;
    }

    private function databaseUtc(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new InvalidArgumentException('authorization_timestamp_invalid');
        }

        return $date->format('Y-m-d H:i:s.v');
    }

    private function encodeCursor(string $id): string
    {
        return rtrim(strtr(base64_encode($id), '+/', '-_'), '=');
    }

    private function decodeCursor(string $cursor): string
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (! is_string($decoded) || $decoded === '' || strlen($decoded) > 128) {
            throw new InvalidArgumentException('authorization_cursor_invalid');
        }

        return $decoded;
    }
}
