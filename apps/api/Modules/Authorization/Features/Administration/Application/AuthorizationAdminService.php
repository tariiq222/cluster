<?php

declare(strict_types=1);

namespace Modules\Authorization\Features\Administration\Application;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Infrastructure\Persistence\AuthorizationHttpGateway;
use Shared\Contracts\RecordAuditEvent;

/**
 * Task 4 — Owns the single outer DB::transaction that wraps every public
 * role/assignment/role-capability mutation exposed by the administration
 * controller. The contract is intentional:
 *
 *  1. The gateway mutator runs FIRST, inside the outer transaction.
 *  2. The audit event is emitted ONCE, immediately after the mutation,
 *     still inside the outer transaction, before commit.
 *  3. If the audit port throws, the outer transaction rolls back, so no
 *     role/role_capability/role_assignment row and no audit_events row is
 *     written. The controller receives the exception and maps it to the
 *     standard 500 problem response.
 *
 * The controller NEVER opens a DB::transaction; this service is the sole
 * owner of the transactional boundary for role and assignment mutations.
 */
final class AuthorizationAdminService
{
    private const SOURCE_MODULE = 'authorization';

    private const SUBJECT_ROLE = 'role';

    private const SUBJECT_ASSIGNMENT = 'role_assignment';

    private const SUBJECT_ROLE_CAPABILITY = 'role_capability';

    private const CLASSIFICATION = 'internal';

    private const RETENTION_CLASS = 'security';

    /**
     * @param  array<string, mixed>  $input
     * @return array{entity: array<string, mixed>, audit: array<string, mixed>}
     */
    public function createRole(array $input, string $actorUserId, string $correlationId, ?string $idempotencyKey = null): array
    {
        return $this->mutate('create-roles', $input, $actorUserId, $idempotencyKey, 201, function () use ($input, $actorUserId, $correlationId): array {
            $entity = $this->gateway->create('roles', $input, $actorUserId);

            $this->emit(
                action: 'authorization.role.created',
                eventType: 'com.cluster.authorization.rolecreated.v1',
                actorUserId: $actorUserId,
                subjectType: self::SUBJECT_ROLE,
                subjectId: (string) $entity['id'],
                correlationId: $correlationId,
                minimizedBefore: null,
                minimizedAfter: $this->roleSnapshot($entity),
            );

            return ['entity' => $entity, 'audit' => ['action' => 'authorization.role.created']];
        });
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array{entity: array<string, mixed>, audit: array<string, mixed>}
     */
    public function editRole(string $roleId, array $patch, int $lockVersion, string $actorUserId, string $correlationId, ?string $idempotencyKey = null): array
    {
        return $this->mutate('patch-roles-'.$roleId, [...$patch, 'if_match' => $lockVersion], $actorUserId, $idempotencyKey, 200, function () use ($roleId, $patch, $lockVersion, $actorUserId, $correlationId): array {
            $before = $this->gateway->find('roles', $roleId, $actorUserId);
            $beforeSnapshot = $before === null ? null : $this->roleSnapshot($before);
            $entity = $this->gateway->update('roles', $roleId, $patch, $lockVersion, $actorUserId);
            if ($entity === null) {
                throw new \InvalidArgumentException('authorization_resource_not_found');
            }

            $this->emit(
                action: 'authorization.role.updated',
                eventType: 'com.cluster.authorization.roleupdated.v1',
                actorUserId: $actorUserId,
                subjectType: self::SUBJECT_ROLE,
                subjectId: (string) $entity['id'],
                correlationId: $correlationId,
                minimizedBefore: $beforeSnapshot,
                minimizedAfter: $this->roleSnapshot($entity),
            );

            return ['entity' => $entity, 'audit' => ['action' => 'authorization.role.updated']];
        });
    }

    /** @return array{entity: array<string, mixed>, audit: array<string, mixed>} */
    public function archiveRole(string $roleId, int $lockVersion, string $actorUserId, string $correlationId, ?string $idempotencyKey = null): array
    {
        return $this->mutate('transition-roles-'.$roleId.'-archive', ['if_match' => $lockVersion], $actorUserId, $idempotencyKey, 200, function () use ($roleId, $lockVersion, $actorUserId, $correlationId): array {
            $before = $this->gateway->find('roles', $roleId, $actorUserId);
            $beforeSnapshot = $before === null ? null : $this->roleSnapshot($before);
            $entity = $this->gateway->update('roles', $roleId, ['status' => 'archived'], $lockVersion, $actorUserId);
            if ($entity === null) {
                throw new \InvalidArgumentException('authorization_resource_not_found');
            }

            $this->emit(
                action: 'authorization.role.archived',
                eventType: 'com.cluster.authorization.rolearchived.v1',
                actorUserId: $actorUserId,
                subjectType: self::SUBJECT_ROLE,
                subjectId: (string) $entity['id'],
                correlationId: $correlationId,
                minimizedBefore: $beforeSnapshot,
                minimizedAfter: $this->roleSnapshot($entity),
            );

            return ['entity' => $entity, 'audit' => ['action' => 'authorization.role.archived']];
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{entity: array<string, mixed>, audit: array<string, mixed>}
     */
    public function cloneRole(string $sourceRoleId, int $expectedVersion, array $overrides, string $actorUserId, string $correlationId, ?string $idempotencyKey = null): array
    {
        return $this->mutate('clone-roles-'.$sourceRoleId, [...$overrides, 'if_match' => $expectedVersion], $actorUserId, $idempotencyKey, 200, function () use ($sourceRoleId, $expectedVersion, $overrides, $actorUserId, $correlationId): array {
            $before = $this->gateway->find('roles', $sourceRoleId, $actorUserId);
            $entity = $this->gateway->transition('roles', $sourceRoleId, 'clone', $expectedVersion, $actorUserId, $overrides);
            if ($entity === null) {
                throw new \InvalidArgumentException('authorization_resource_not_found');
            }

            $this->emit(
                action: 'authorization.role.cloned',
                eventType: 'com.cluster.authorization.rolecloned.v1',
                actorUserId: $actorUserId,
                subjectType: self::SUBJECT_ROLE,
                subjectId: (string) $entity['id'],
                correlationId: $correlationId,
                minimizedBefore: null,
                minimizedAfter: $this->roleSnapshot($entity),
                context: ['source' => ['role_id' => $sourceRoleId, 'capability_ids' => $this->capabilityIds($sourceRoleId)]],
            );

            return ['entity' => $entity, 'audit' => ['action' => 'authorization.role.cloned']];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{entity: array<string, mixed>, audit: array<string, mixed>}
     */
    public function createAssignment(array $input, string $actorUserId, string $correlationId, ?string $idempotencyKey = null): array
    {
        return $this->mutate('create-role-assignments', $input, $actorUserId, $idempotencyKey, 201, function () use ($input, $actorUserId, $correlationId): array {
            $entity = $this->gateway->create('role-assignments', $input, $actorUserId);

            $this->emit(
                action: 'authorization.assignment.created',
                eventType: 'com.cluster.authorization.assignmentcreated.v1',
                actorUserId: $actorUserId,
                subjectType: self::SUBJECT_ASSIGNMENT,
                subjectId: (string) $entity['id'],
                correlationId: $correlationId,
                minimizedBefore: null,
                minimizedAfter: $this->minimize($entity),
            );

            return ['entity' => $entity, 'audit' => ['action' => 'authorization.assignment.created']];
        });
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array{entity: array<string, mixed>, audit: array<string, mixed>}
     */
    public function updateAssignment(string $assignmentId, array $patch, int $lockVersion, string $actorUserId, string $correlationId, ?string $idempotencyKey = null): array
    {
        return $this->mutate('patch-role-assignments-'.$assignmentId, [...$patch, 'if_match' => $lockVersion], $actorUserId, $idempotencyKey, 200, function () use ($assignmentId, $patch, $lockVersion, $actorUserId, $correlationId): array {
            $before = $this->gateway->find('role-assignments', $assignmentId, $actorUserId);
            $entity = $this->gateway->update('role-assignments', $assignmentId, $patch, $lockVersion, $actorUserId);
            if ($entity === null) {
                throw new \InvalidArgumentException('authorization_resource_not_found');
            }

            $this->emit(
                action: 'authorization.assignment.updated',
                eventType: 'com.cluster.authorization.assignmentupdated.v1',
                actorUserId: $actorUserId,
                subjectType: self::SUBJECT_ASSIGNMENT,
                subjectId: (string) $entity['id'],
                correlationId: $correlationId,
                minimizedBefore: $this->minimize($before),
                minimizedAfter: $this->minimize($entity),
            );

            return ['entity' => $entity, 'audit' => ['action' => 'authorization.assignment.updated']];
        });
    }

    /** @return array{entity: array<string, mixed>, audit: array<string, mixed>} */
    public function revokeAssignment(string $assignmentId, int $lockVersion, string $actorUserId, string $correlationId, ?string $idempotencyKey = null): array
    {
        return $this->mutate('transition-role-assignments-'.$assignmentId.'-revoke', ['if_match' => $lockVersion], $actorUserId, $idempotencyKey, 200, function () use ($assignmentId, $lockVersion, $actorUserId, $correlationId): array {
            $before = $this->gateway->find('role-assignments', $assignmentId, $actorUserId);
            $entity = $this->gateway->transition('role-assignments', $assignmentId, 'revoke', $lockVersion, $actorUserId);
            if ($entity === null) {
                throw new \InvalidArgumentException('authorization_resource_not_found');
            }

            $this->emit(
                action: 'authorization.assignment.revoked',
                eventType: 'com.cluster.authorization.assignmentrevoked.v1',
                actorUserId: $actorUserId,
                subjectType: self::SUBJECT_ASSIGNMENT,
                subjectId: (string) $entity['id'],
                correlationId: $correlationId,
                minimizedBefore: $this->minimize($before),
                minimizedAfter: $this->minimize($entity),
            );

            return ['entity' => $entity, 'audit' => ['action' => 'authorization.assignment.revoked']];
        });
    }

    /** @return array{entity: array<string, mixed>, audit: array<string, mixed>} */
    public function expireAssignment(string $assignmentId, int $lockVersion, string $actorUserId, string $correlationId, ?string $idempotencyKey = null): array
    {
        return $this->mutate('transition-role-assignments-'.$assignmentId.'-expire', ['if_match' => $lockVersion], $actorUserId, $idempotencyKey, 200, function () use ($assignmentId, $lockVersion, $actorUserId, $correlationId): array {
            $before = $this->gateway->find('role-assignments', $assignmentId, $actorUserId);
            $entity = $this->gateway->transition('role-assignments', $assignmentId, 'expire', $lockVersion, $actorUserId);
            if ($entity === null) {
                throw new \InvalidArgumentException('authorization_resource_not_found');
            }

            $this->emit(
                action: 'authorization.assignment.expired',
                eventType: 'com.cluster.authorization.assignmentexpired.v1',
                actorUserId: $actorUserId,
                subjectType: self::SUBJECT_ASSIGNMENT,
                subjectId: (string) $entity['id'],
                correlationId: $correlationId,
                minimizedBefore: $this->minimize($before),
                minimizedAfter: $this->minimize($entity),
            );

            return ['entity' => $entity, 'audit' => ['action' => 'authorization.assignment.expired']];
        });
    }

    /** @return array{entity: array<string, mixed>, audit: array<string, mixed>} */
    public function revokeRoleCapability(string $roleCapabilityId, int $lockVersion, string $actorUserId, string $correlationId, ?string $idempotencyKey = null): array
    {
        return $this->mutate('transition-role-capabilities-'.$roleCapabilityId.'-revoke', ['if_match' => $lockVersion], $actorUserId, $idempotencyKey, 200, function () use ($roleCapabilityId, $lockVersion, $actorUserId, $correlationId): array {
            $before = $this->gateway->find('role-capabilities', $roleCapabilityId, $actorUserId);
            $entity = $this->gateway->transition('role-capabilities', $roleCapabilityId, 'revoke', $lockVersion, $actorUserId);
            if ($entity === null) {
                throw new \InvalidArgumentException('authorization_resource_not_found');
            }

            $this->emit(
                action: 'authorization.role_capability.revoked',
                eventType: 'com.cluster.authorization.rolecapabilityrevoked.v1',
                actorUserId: $actorUserId,
                subjectType: self::SUBJECT_ROLE_CAPABILITY,
                subjectId: null,
                correlationId: $correlationId,
                minimizedBefore: $this->minimize($before),
                minimizedAfter: $this->minimize($entity),
                context: [
                    'role_id' => (string) $entity['role_id'],
                    'capability_id' => (string) $entity['capability_id'],
                ],
            );

            return ['entity' => $entity, 'audit' => ['action' => 'authorization.role_capability.revoked']];
        });
    }

    /**
     * Claims, replays, and persists one idempotent response in the same
     * transaction as the mutation and its audit event.
     *
     * @param array<string, mixed> $request
     * @param callable(): array{entity: array<string, mixed>, audit: array<string, mixed>} $mutation
     * @return array{entity: array<string, mixed>, audit: array<string, mixed>}
     */
    private function mutate(string $operation, array $request, string $actorUserId, ?string $idempotencyKey, int $status, callable $mutation): array
    {
        try {
            return DB::transaction(function () use ($operation, $request, $actorUserId, $idempotencyKey, $status, $mutation): array {
            $requestHash = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));
            if ($idempotencyKey !== null) {
                $entry = DB::table('authorization_idempotency_keys')
                    ->where('principal_id', $actorUserId)
                    ->where('operation', $operation)
                    ->where('key_hash', hash('sha256', $idempotencyKey))
                    ->lockForUpdate()
                    ->first();
                if ($entry !== null) {
                    if (! hash_equals((string) $entry->request_hash, $requestHash)) {
                        throw new \InvalidArgumentException('authorization_idempotency_conflict');
                    }
                    $payload = json_decode((string) $entry->response_payload, true);
                    if (! is_array($payload) || ! is_array($payload['data'] ?? null)) {
                        throw new \RuntimeException('authorization_idempotency_state_unavailable');
                    }

                    return ['entity' => $payload['data'], 'audit' => ['replayed' => true]];
                }
            }

            $result = $mutation();
            if ($idempotencyKey !== null) {
                DB::table('authorization_idempotency_keys')->insert([
                    'principal_id' => $actorUserId,
                    'operation' => $operation,
                    'key_hash' => hash('sha256', $idempotencyKey),
                    'request_hash' => $requestHash,
                    'resource_id' => mb_strlen((string) $result['entity']['id']) > 64 ? md5((string) $result['entity']['id']) : (string) $result['entity']['id'],
                    'response_status' => $status,
                    'response_payload' => json_encode(['data' => $result['entity']], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

                return $result;
            });
        } catch (QueryException $exception) {
            if ($idempotencyKey === null || (string) $exception->getCode() !== '23000') {
                throw $exception;
            }
            $entry = DB::table('authorization_idempotency_keys')->where([
                'principal_id' => $actorUserId,
                'operation' => $operation,
                'key_hash' => hash('sha256', $idempotencyKey),
            ])->first();
            $requestHash = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));
            if ($entry === null) {
                throw $exception;
            }
            if (! hash_equals((string) $entry->request_hash, $requestHash)) {
                throw new \InvalidArgumentException('authorization_idempotency_conflict');
            }
            $payload = json_decode((string) $entry->response_payload, true);
            if (! is_array($payload) || ! is_array($payload['data'] ?? null)) {
                throw new \RuntimeException('authorization_idempotency_state_unavailable');
            }

            return ['entity' => $payload['data'], 'audit' => ['replayed' => true]];
        }
    }

    public function __construct(
        private readonly AuthorizationHttpGateway $gateway,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  array<string, mixed>|null  $minimizedBefore
     * @param  array<string, mixed>  $minimizedAfter
     */
    private function emit(
        string $action,
        string $eventType,
        string $actorUserId,
        string $subjectType,
        ?string $subjectId,
        string $correlationId,
        ?array $minimizedBefore,
        array $minimizedAfter,
        array $context = [],
    ): void {
        $occurredAt = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s.v\Z',
            now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            new DateTimeZone('UTC'),
        );
        if ($occurredAt === false) {
            $occurredAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        $this->audit->record([
            'event_id' => Str::uuid7()->toString(),
            'source_module' => self::SOURCE_MODULE,
            'action' => $action,
            'event_type' => $eventType,
            'actor_type' => 'user',
            'actor_id' => $actorUserId,
            'original_actor_id' => null,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'correlation_id' => $correlationId,
            'outcome' => 'succeeded',
            'classification' => self::CLASSIFICATION,
            'retention_class' => self::RETENTION_CLASS,
            'occurred_at' => $occurredAt->format('Y-m-d\TH:i:s.v\Z'),
            'context' => [...$context, 'before' => $minimizedBefore, 'after' => $minimizedAfter],
        ]);
    }

    /** @param array<string, mixed> $entity */
    private function roleSnapshot(array $entity): array
    {
        return [...($this->minimize($entity) ?? []), 'capability_ids' => $this->capabilityIds((string) $entity['id'])];
    }

    /** @return list<string> */
    private function capabilityIds(string $roleId): array
    {
        return array_values(DB::table('role_capabilities')
            ->where('role_id', $roleId)
            ->orderBy('capability_id')
            ->pluck('capability_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all());
    }

    /**
     * Produce a minimized snapshot of the post-mutation entity so the audit
     * context stays small and deterministic. Raw timestamps and lock
     * versions are intentionally retained because they are the only
     * evidence that the mutation actually happened.
     *
     * @param  array<string, mixed>|null  $entity
     * @return array<string, mixed>|null
     */
    private function minimize(?array $entity): ?array
    {
        if ($entity === null) {
            return null;
        }

        $keep = [
            'id', 'code', 'name_ar', 'name_en', 'role_type', 'status',
            'is_system_role', 'lock_version', 'scope_type', 'scope_id',
            'user_id', 'role_id', 'effect', 'start_at', 'end_at',
            'capability_id', 'resource_type',
        ];
        $minimized = [];
        foreach ($keep as $key) {
            if (array_key_exists($key, $entity)) {
                $minimized[$key] = $entity[$key];
            }
        }

        return $minimized;
    }
}
