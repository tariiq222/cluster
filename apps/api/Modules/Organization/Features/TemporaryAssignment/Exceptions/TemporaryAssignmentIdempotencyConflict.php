<?php

namespace Modules\Organization\Features\TemporaryAssignment\Exceptions;

use DomainException;

final class TemporaryAssignmentIdempotencyConflict extends DomainException
{
    public function __construct()
    {
        parent::__construct('temporary_assignment_idempotency_conflict');
    }
}
