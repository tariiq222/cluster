<?php

namespace Modules\Identity\Features\Authentication\Contracts;

use Modules\Identity\Features\Sessions\Contracts\SessionTransport;

interface AuthenticateUser
{
    /** @param array<string, mixed> $metadata */
    public function authenticate(string $username, string $password, ?string $totpCode = null, array $metadata = [], ?string $source = null): SessionTransport;
}
