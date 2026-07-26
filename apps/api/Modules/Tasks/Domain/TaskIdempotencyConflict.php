<?php

namespace Modules\Tasks\Domain;

use DomainException;

final class TaskIdempotencyConflict extends DomainException
{
    public function __construct()
    {
        parent::__construct('Idempotency-Key was already used for a different request.');
    }
}
