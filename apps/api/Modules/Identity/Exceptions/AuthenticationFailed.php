<?php

namespace Modules\Identity\Exceptions;

use RuntimeException;

final class AuthenticationFailed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Authentication failed.');
    }
}
