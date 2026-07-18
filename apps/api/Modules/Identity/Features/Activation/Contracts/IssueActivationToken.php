<?php

namespace Modules\Identity\Features\Activation\Contracts;

interface IssueActivationToken
{
    /** @return array{user_id: string, token: string, expires_at: string} */
    public function issue(string $userId): array;
}
