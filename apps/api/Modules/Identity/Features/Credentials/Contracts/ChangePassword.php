<?php

namespace Modules\Identity\Features\Credentials\Contracts;

interface ChangePassword
{
    public function change(string $userId, string $currentPassword, string $newPassword): void;
}
