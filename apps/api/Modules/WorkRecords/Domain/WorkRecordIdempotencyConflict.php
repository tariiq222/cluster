<?php

declare(strict_types=1);

namespace Modules\WorkRecords\Domain;

use DomainException;

final class WorkRecordIdempotencyConflict extends DomainException
{
    public function __construct()
    {
        parent::__construct('Idempotency-Key was already used for a different request.');
    }
}
