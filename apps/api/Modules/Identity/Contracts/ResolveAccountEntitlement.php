<?php

namespace Modules\Identity\Contracts;

interface ResolveAccountEntitlement
{
    /** @return array{active: bool, administrator: bool}|null */
    public function resolve(string $userId): ?array;
}
