<?php

namespace Modules\Identity\Domain;

use Modules\Identity\Exceptions\WeakPassword;
use Modules\Identity\Features\Credentials\Contracts\UsernameDenylist;
use Modules\Identity\Infrastructure\Security\LocalUsernameDenylist;
use Modules\PlatformSettings\Contracts\GetEffectivePlatformSettings;
use Throwable;

final class PasswordPolicy
{
    /** @var array<string, int>|null */
    private ?array $cachedSecurity = null;

    public function __construct(
        private readonly UsernameDenylist $denylist,
        private readonly ?GetEffectivePlatformSettings $settings = null,
    ) {}

    /** @return list<string> */
    public function violations(string $password, ?string $username = null): array
    {
        $security = $this->securitySnapshot();
        $min = (int) ($security['minimum_password_length'] ?? config('identity.password.min_length', 14));
        $max = (int) config('identity.password.max_length', 128);
        $violations = [];

        if (mb_strlen($password) < $min) {
            $violations[] = 'min_length';
        }
        if (mb_strlen($password) > $max) {
            $violations[] = 'max_length';
        }
        $denylist = $this->denylist ?? new LocalUsernameDenylist;
        if ($denylist->contains($password)) {
            $violations[] = 'common_password';
        }
        if (preg_match('/(.)\1\1\1/', $password) === 1) {
            $violations[] = 'repeated_characters';
        }

        $normalizedUsername = $username === null ? null : UserAccount::normalizeUsername($username);
        if ($normalizedUsername !== null && $normalizedUsername !== '') {
            $passwordLower = mb_strtolower($password);
            $fragments = preg_split('/[^\p{L}\p{N}]+/u', $normalizedUsername, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $fragments[] = $normalizedUsername;
            foreach (array_unique($fragments) as $fragment) {
                if (mb_strlen($fragment) >= 3 && str_contains($passwordLower, $fragment)) {
                    $violations[] = 'contains_username';
                    break;
                }
            }
        }

        return array_values(array_unique($violations));
    }

    public function assertValid(string $password, ?string $username = null): void
    {
        $violations = $this->violations($password, $username);
        if ($violations !== []) {
            throw new WeakPassword($violations);
        }
    }

    /** @return array<string, int> */
    private function securitySnapshot(): array
    {
        if ($this->settings === null) {
            return [];
        }
        if ($this->cachedSecurity !== null) {
            return $this->cachedSecurity;
        }
        try {
            if (! $this->settings->hasPublishedVersion()) {
                $this->cachedSecurity = [];
            } else {
                $current = $this->settings->current();
                $this->cachedSecurity = $current['security'];
            }
        } catch (Throwable) {
            // Retain the last successful snapshot; on first failure keep the contract empty.
        }

        return $this->cachedSecurity ?? [];
    }
}
