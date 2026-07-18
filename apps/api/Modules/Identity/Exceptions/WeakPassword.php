<?php

namespace Modules\Identity\Exceptions;

use InvalidArgumentException;

final class WeakPassword extends InvalidArgumentException
{
    /** @param list<string> $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('The password does not satisfy the current policy.');
    }
}
