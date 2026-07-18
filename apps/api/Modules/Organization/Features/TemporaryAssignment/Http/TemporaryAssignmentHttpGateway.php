<?php

namespace Modules\Organization\Features\TemporaryAssignment\Http;

interface TemporaryAssignmentHttpGateway
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @return array{created: bool, temporary_assignment: array<string, mixed>}
     */
    public function create(
        string $temporaryAssignmentId,
        array $input,
        string $actorId,
        string $correlationId,
        array $idempotency,
    ): array;

    /** @return array<string, mixed>|null */
    public function findInUnit(string $organizationUnitId, string $temporaryAssignmentId): ?array;

    /** @return array{items: list<array<string, mixed>>, next_cursor: string|null} */
    public function listInUnit(string $organizationUnitId, ?string $cursor, int $limit): array;

    /**
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @return array{changed: bool, temporary_assignment: array<string, mixed>}
     */
    public function revoke(
        string $organizationUnitId,
        string $temporaryAssignmentId,
        int $expectedVersion,
        string $reason,
        string $actorId,
        string $correlationId,
        array $idempotency,
    ): array;
}
