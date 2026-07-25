<?php

namespace App\Http\Middleware;

final class IdentityRequestAttributes
{
    public const CORRELATION_ID = 'identity.correlation_id';

    public const PRINCIPAL = 'identity.principal';

    public const SESSION = 'identity.session';
}
