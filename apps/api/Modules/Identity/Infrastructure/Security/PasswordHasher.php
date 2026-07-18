<?php

namespace Modules\Identity\Infrastructure\Security;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Hash;

final class PasswordHasher
{
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=3,p=1$eTkuTUUycUMwbXJMcm5sWA$awhni/sLq0oQROjM0vg5yyuBUPTeFKV2+e0cfoiRxhg';

    private readonly Hasher $hasher;

    public function __construct(?Hasher $hasher = null)
    {
        $this->hasher = $hasher ?? Hash::driver('argon2id');
    }

    public function hash(string $password): string
    {
        return $this->hasher->make($password);
    }

    public function check(string $password, string $hash): bool
    {
        return $this->hasher->check($password, $hash);
    }

    public function dummyCheck(string $password): void
    {
        $this->check($password, self::DUMMY_HASH);
    }

    public function dummyHash(): string
    {
        return self::DUMMY_HASH;
    }

    public function needsRehash(string $hash): bool
    {
        return $this->hasher->needsRehash($hash);
    }

    public function algorithm(): string
    {
        return 'argon2id';
    }
}
