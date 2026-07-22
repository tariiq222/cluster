<?php

namespace Modules\Identity\Contracts;

interface ResolveUserForPerson
{
    public function forPerson(string $personId): ?string;
}
