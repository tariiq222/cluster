<?php

namespace Modules\Workflow\Infrastructure\Persistence;

use DomainException;

/**
 * Surfaced when a caller submits an advance against a workflow step whose
 * lock_version no longer matches the version the caller observed. The
 * WorkflowStepAdvancer raises this from its SQL CAS predicate so the caller
 * can distinguish a stale write from a legitimate "step already done" no-op.
 */
final class StaleWorkflowStepVersion extends DomainException
{
    public function __construct(
        public readonly string $stepId,
        public readonly int $expectedVersion,
        public readonly int $actualVersion,
    ) {
        parent::__construct(sprintf(
            'Workflow step %s expected lock_version %d but found %d.',
            $stepId,
            $expectedVersion,
            $actualVersion,
        ));
    }
}
