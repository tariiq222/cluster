<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure\Persistence;

use RuntimeException;
use Throwable;

/**
 * Typed conflict surfaced when a concurrent writer already claimed the
 * (principalId, operation, keyHash) triple before this transaction
 * inserted its row.
 *
 * Callers must roll back the surrounding DB transaction so no partial
 * state (descriptor, audit activity, outbox row) survives the loss. The
 * matching Task 5 / Task 6 controller then re-reads via
 * {@see AuditIdempotencyStore::findResponse()} and returns the stored
 * response to the client.
 */
final class AuditIdempotencyConflict extends RuntimeException
{
    public function __construct(
        public readonly string $principalId,
        public readonly string $operation,
        public readonly string $keyHash,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'audit_idempotency_key_already_claimed:%s:%s',
                $operation,
                substr($keyHash, 0, 12),
            ),
            0,
            $previous,
        );
    }
}
