<?php

namespace Modules\Tasks\Features\CompleteTask\Handler;

use DomainException;

/**
 * Surfaced when CompleteTaskHandler is asked to complete a task whose
 * lock_version no longer matches the version the caller observed. The handler
 * raises this from its SQL CAS predicate so callers can distinguish a stale
 * submit from a legitimate idempotent "already completed" outcome.
 */
final class StaleTaskVersion extends DomainException
{
    public function __construct(
        public readonly string $taskId,
        public readonly int $expectedVersion,
    ) {
        parent::__construct(sprintf(
            'Task %s expected lock_version %d.',
            $taskId,
            $expectedVersion,
        ));
    }
}