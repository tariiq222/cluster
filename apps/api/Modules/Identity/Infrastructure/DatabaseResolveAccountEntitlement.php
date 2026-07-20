<?php

namespace Modules\Identity\Infrastructure;

use Illuminate\Database\ConnectionInterface;
use Modules\Identity\Contracts\ResolveAccountEntitlement;

final class DatabaseResolveAccountEntitlement implements ResolveAccountEntitlement
{
    public function __construct(private readonly ConnectionInterface $persistence) {}

    public function resolve(string $userId): ?array
    {
        $account = $this->persistence->table('users')
            ->where('id', $userId)
            ->first(['status', 'is_admin']);

        return $account === null ? null : [
            'active' => $account->status === 'active',
            'administrator' => (bool) $account->is_admin,
        ];
    }
}
