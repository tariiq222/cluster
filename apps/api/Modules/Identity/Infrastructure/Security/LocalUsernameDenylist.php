<?php

namespace Modules\Identity\Infrastructure\Security;

use Modules\Identity\Features\Credentials\Contracts\UsernameDenylist;
use RuntimeException;
use SplFileObject;

final class LocalUsernameDenylist implements UsernameDenylist
{
    public function contains(string $candidate): bool
    {
        $candidate = mb_strtolower(trim($candidate));
        $path = (string) config('identity.password.denylist.path', '');
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            if (app()->environment('testing')) {
                return false;
            }
            throw new RuntimeException('The configured local Identity password denylist is unavailable.');
        }

        $file = new SplFileObject($path, 'rb');
        foreach ($file as $line) {
            if (! is_string($line)) {
                continue;
            }
            $denied = mb_strtolower(trim($line));
            $denied = preg_replace('/\A\xEF\xBB\xBF/', '', $denied) ?? $denied;
            if ($denied === '' || str_starts_with($denied, '#')) {
                continue;
            }
            if (hash_equals($denied, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
