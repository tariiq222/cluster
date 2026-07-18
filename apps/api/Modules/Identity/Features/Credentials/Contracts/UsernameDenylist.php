<?php

namespace Modules\Identity\Features\Credentials\Contracts;

interface UsernameDenylist
{
    public function contains(string $candidate): bool;
}
