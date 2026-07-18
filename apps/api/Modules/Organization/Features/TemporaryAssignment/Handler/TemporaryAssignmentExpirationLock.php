<?php

namespace Modules\Organization\Features\TemporaryAssignment\Handler;

final class TemporaryAssignmentExpirationLock
{
    public function valueFor(string $driver): bool|string
    {
        return in_array($driver, ['mysql', 'pgsql'], true)
            ? 'for update skip locked'
            : true;
    }
}
