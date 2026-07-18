<?php

namespace Modules\Organization\Features\TemporaryAssignment\Http;

use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Modules\Organization\Features\TemporaryAssignment\Handler\TemporaryAssignmentHandler;
use UnexpectedValueException;

final class DatabaseTemporaryAssignmentHttpGateway implements TemporaryAssignmentHttpGateway
{
    private const MAX_PAGE_SIZE = 100;

    public function __construct(private readonly TemporaryAssignmentHandler $handler) {}

    public function create(
        string $temporaryAssignmentId,
        array $input,
        string $actorId,
        string $correlationId,
        array $idempotency,
    ): array {
        $result = $this->handler->create(
            $temporaryAssignmentId,
            $input,
            $actorId,
            $correlationId,
            $idempotency,
        );
        if ($result['created']) {
            $result['temporary_assignment'] = $this->findRequired(
                (string) $result['temporary_assignment']['id'],
            );
        }

        return $result;
    }

    public function find(string $temporaryAssignmentId): ?array
    {
        $this->assertIdentifier($temporaryAssignmentId);

        return $this->handler->find($temporaryAssignmentId);
    }

    public function listInUnit(string $organizationUnitId, ?string $cursor, int $limit): array
    {
        $this->assertIdentifier($organizationUnitId);
        if ($limit < 1 || $limit > self::MAX_PAGE_SIZE) {
            throw new InvalidArgumentException('temporary_assignment_page_limit_invalid');
        }
        if (! DB::table('organization_units')->where('id', $organizationUnitId)->exists()) {
            throw new DomainException('organization_unit_not_found');
        }
        $after = $cursor === null ? null : $this->decodeCursor($cursor, $organizationUnitId, $limit);

        $query = DB::table('temporary_assignments')
            ->where('organization_unit_id', $organizationUnitId)
            ->orderBy('id')
            ->limit($limit + 1);
        if ($after !== null) {
            $query->where('id', '>', $after);
        }

        /** @var list<string> $ids */
        $ids = $query->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
        $hasMore = count($ids) > $limit;
        $visibleIds = array_slice($ids, 0, $limit);
        $items = array_map(
            fn (string $id): array => $this->findRequired($id),
            $visibleIds,
        );
        $lastId = $visibleIds === [] ? null : $visibleIds[array_key_last($visibleIds)];

        return [
            'items' => $items,
            'next_cursor' => $hasMore && $lastId !== null
                ? $this->encodeCursor($lastId, $organizationUnitId, $limit)
                : null,
        ];
    }

    public function revoke(
        string $temporaryAssignmentId,
        int $expectedVersion,
        string $reason,
        string $actorId,
        string $correlationId,
        array $idempotency,
    ): array {
        if ($this->find($temporaryAssignmentId) === null) {
            throw new DomainException('temporary_assignment_not_found');
        }
        $result = $this->handler->revoke(
            $temporaryAssignmentId,
            $expectedVersion,
            $reason,
            $actorId,
            $correlationId,
            $idempotency,
        );
        if ($result['changed']) {
            $result['temporary_assignment'] = $this->findRequired($temporaryAssignmentId);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function findRequired(string $temporaryAssignmentId): array
    {
        $temporaryAssignment = $this->find($temporaryAssignmentId);
        if ($temporaryAssignment === null) {
            throw new UnexpectedValueException('The temporary assignment could not be read after its write.');
        }

        return $temporaryAssignment;
    }

    private function encodeCursor(string $after, string $organizationUnitId, int $limit): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 1,
            'after' => $after,
            'scope' => ['organization_unit_id' => $organizationUnitId],
            'query' => ['limit' => $limit],
        ], JSON_THROW_ON_ERROR));
    }

    private function decodeCursor(string $cursor, string $organizationUnitId, int $limit): string
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new InvalidArgumentException('temporary_assignment_cursor_invalid');
        }
        if (! is_array($payload)
            || array_keys($payload) !== ['version', 'after', 'scope', 'query']
            || $payload['version'] !== 1
            || ! is_string($payload['after'] ?? null)
            || ! is_array($payload['scope'] ?? null)
            || $payload['scope'] !== ['organization_unit_id' => $organizationUnitId]
            || ! is_array($payload['query'] ?? null)
            || $payload['query'] !== ['limit' => $limit]) {
            throw new InvalidArgumentException('temporary_assignment_cursor_invalid');
        }
        $this->assertIdentifier($payload['after']);

        return $payload['after'];
    }

    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $identifier) !== 1) {
            throw new InvalidArgumentException('temporary_assignment_reference_invalid');
        }
    }
}
