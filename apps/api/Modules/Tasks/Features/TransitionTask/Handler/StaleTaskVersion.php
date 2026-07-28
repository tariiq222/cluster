<?php

namespace Modules\Tasks\Features\TransitionTask\Handler;

use DomainException;

/**
 * Surfaced when TransitionTaskHandler is asked to transition a task whose
 * lock_version no longer matches the version the caller observed. The handler
 * raises this from its SQL CAS predicate so callers can distinguish a stale
 * submit from a legitimate idempotent replay.
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
