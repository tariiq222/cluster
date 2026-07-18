<?php

namespace Modules\Authorization\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Authorization\Domain\Capability;
use Modules\Authorization\Domain\Delegation;
use Modules\Authorization\Domain\Role;
use Modules\Authorization\Domain\RoleAssignment;

final class AuthorizationHttpGateway
{
    /** @var array<string, string> */
    private const TABLES = [
        'roles' => 'roles',
        'capabilities' => 'capabilities',
        'role-assignments' => 'role_assignments',
        'delegations' => 'delegations',
        'classification-policies' => 'classification_policies',
        'field-access-templates' => 'field_access_templates',
    ];

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
    public function list(string $resource, ?string $cursor, int $limit): array
    {
        $table = $this->requireTable($resource);
        $query = DB::table($table)->orderBy('id');
        if ($cursor !== null) {
            $cursorId = $this->decodeCursor($cursor);
            $query->where('id', '>', $cursorId);
        }
        $rows = $query->limit($limit + 1)->get();
        $nextCursor = null;
        if ($rows->count() > $limit) {
            $last = $rows->pop();
            $nextCursor = $this->encodeCursor((string) $last->id);
        }

        return [
            'items' => $rows->map(fn (object $row): array => $this->serialize($resource, $row))->values()->all(),
            'next_cursor' => $nextCursor,
        ];
    }

    /** @return array<string,mixed>|null */
    public function find(string $resource, string $id): ?array
    {
        $table = $this->requireTable($resource);
        $row = DB::table($table)->where('id', $id)->first();

        return $row === null ? null : $this->serialize($resource, $row);
    }

    /** @param array<string,mixed> $input */
    /** @return array<string,mixed> */
    public function create(string $resource, array $input, string $principalId): array
    {
        $table = $this->requireTable($resource);
        $id = Str::uuid7()->toString();
        $now = now()->utc()->format('Y-m-d H:i:s.v');
        $row = match ($resource) {
            'roles' => $this->role($id, $input, $now),
            'capabilities' => $this->capability($id, $input, $now),
            'role-assignments' => $this->roleAssignment($id, $input, $principalId, $now),
            'delegations' => $this->delegation($id, $input, $now),
            'classification-policies' => $this->classificationPolicy($id, $input, $now),
            'field-access-templates' => $this->fieldTemplate($id, $input, $now),
            default => throw new InvalidArgumentException('authorization_resource_invalid'),
        };
        $capabilityCodes = $row['_capability_codes'] ?? null;
        unset($row['_capability_codes']);
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
    public function update(string $resource, string $id, array $patch, int $expectedVersion): ?array
    {
        $table = $this->requireTable($resource);
        $changes = $this->normalisePatch($resource, $patch);
        if ($changes === []) {
            throw new InvalidArgumentException('authorization_patch_empty');
        }
        $changes['lock_version'] = $expectedVersion + 1;
        $changes['updated_at'] = now()->utc()->format('Y-m-d H:i:s.v');
        $updated = DB::table($table)->where('id', $id)->where('lock_version', $expectedVersion)->update($changes);
        if ($updated === 0) {
            $exists = DB::table($table)->where('id', $id)->exists();
            if (! $exists) {
                return null;
            }
            throw new InvalidArgumentException('authorization_precondition_failed');
        }

        return $this->find($resource, $id);
    }

    /** @return array<string,mixed>|null */
    public function transition(string $resource, string $id, string $action, int $expectedVersion): ?array
    {
        if (! in_array($action, ['activate', 'revoke', 'expire', 'publish'], true)) {
            throw new InvalidArgumentException('authorization_action_invalid');
        }
        if ($resource === 'roles' || $resource === 'capabilities') {
            throw new InvalidArgumentException('authorization_action_unsupported');
        }
        $status = match ($action) {
            'activate' => 'active',
            'revoke' => 'revoked',
            'expire' => 'expired',
            'publish' => 'published',
        };

        return $this->update($resource, $id, ['status' => $status], $expectedVersion);
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
        $module = (string) ($input['module_code'] ?? explode('.', $code, 2)[0]);
        $action = (string) ($input['action'] ?? (strrchr($code, '.') !== false ? substr((string) strrchr($code, '.'), 1) : 'read'));
        $capability = Capability::define($id, $module, $code, $action, (string) ($input['sensitivity'] ?? 'normal'))->toArray();

        return [...$capability, 'created_at' => $now, 'updated_at' => $now, 'lock_version' => 1];
    }

    /** @param array<string,mixed> $input */
    /** @return array<string,mixed> */
    private function roleAssignment(string $id, array $input, string $principalId, string $now): array
    {
        $assignment = RoleAssignment::assign(
            $id,
            (string) ($input['user_id'] ?? $input['subject_user_id'] ?? ''),
            (string) ($input['role_id'] ?? ''),
            isset($input['scope_id']) ? (string) $input['scope_id'] : null,
            $this->domainUtc((string) ($input['start_at'] ?? '')),
            array_key_exists('end_at', $input) && $input['end_at'] !== null ? $this->domainUtc((string) $input['end_at']) : null,
            $principalId,
        )->toArray();

        return [
            ...$assignment,
            'start_at' => $this->databaseUtc($assignment['start_at']),
            'end_at' => $assignment['end_at'] === null ? null : $this->databaseUtc($assignment['end_at']),
            'created_at' => $now,
            'updated_at' => $now,
            'lock_version' => 1,
        ];
    }

    /** @param array<string,mixed> $input */
    /** @return array<string,mixed> */
    private function delegation(string $id, array $input, string $now): array
    {
        /** @var list<string> $codes */
        $codes = array_values($input['capability_codes'] ?? []);
        $delegation = Delegation::create(
            $id,
            (string) ($input['delegator_user_id'] ?? ''),
            (string) ($input['delegate_user_id'] ?? $input['subject_user_id'] ?? ''),
            (string) ($input['module_code'] ?? ''),
            $codes,
            isset($input['scope_id']) ? (string) $input['scope_id'] : null,
            $this->domainUtc((string) ($input['start_at'] ?? '')),
            $this->domainUtc((string) ($input['end_at'] ?? '')),
        )->toArray();

        return [
            ...$delegation,
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
            'role-assignments' => ['end_at', 'status'],
            'delegations' => ['end_at', 'status'],
            'classification-policies' => ['status', 'policy_document', 'is_active', 'export_policy', 'download_policy', 'policy_version'],
            'field-access-templates' => ['status', 'policy_document', 'is_active'],
            default => [],
        };
        if (array_diff(array_keys($patch), $allowed) !== []) {
            throw new InvalidArgumentException('authorization_patch_invalid');
        }
        $changes = $patch;
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

        return $changes;
    }

    /** @return array<string,mixed> */
    private function serialize(string $resource, object $row): array
    {
        $values = get_object_vars($row);
        unset($values['created_at'], $values['updated_at']);
        $values['resource_type'] = match ($resource) {
            'roles' => 'role',
            'capabilities' => 'capability',
            'role-assignments' => 'role_assignment',
            'delegations' => 'delegation',
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
        if (! is_string($decoded) || $decoded === '' || strlen($decoded) > 64) {
            throw new InvalidArgumentException('authorization_cursor_invalid');
        }

        return $decoded;
    }
}
