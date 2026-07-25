<?php

namespace Modules\Organization\Infrastructure\Authorization;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Organization\Contracts\ResolveDevelopmentFixturePrincipal;

/**
 * Organization-owned principal resolver. Reads the principal from either the
 * bearer token (development fixture) cached in the file cache store, so the
 * Organization controllers can resolve a principal without importing the
 * higher-ranked Identity module.
 */
final class OrganizationPrincipalResolver implements ResolveDevelopmentFixturePrincipal
{
    private const TOKEN_PATTERN = '/\A[A-Za-z0-9]{64}\z/';

    private const UUID_V7_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    public function issue(array $principal): array
    {
        return [
            'access_token' => 'unused-by-organization-resolver',
            'expires_at' => now()->utc()->toIso8601String(),
        ];
    }

    /**
     * @return array{user_id: string, facility_id: string}|null
     */
    public function resolve(Request $request): ?array
    {
        $token = $request->bearerToken();
        if (! is_string($token) || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        $credential = $this->store()->get($this->cacheKey($token));
        if (! is_array($credential)
            || ! is_int($credential['expires_at'] ?? null)
            || $credential['expires_at'] <= now()->getTimestamp()
            || ! is_array($credential['principal'] ?? null)
            || ! $this->isUuidV7((string) ($credential['principal']['user_id'] ?? ''))
            || ! $this->isUuidV7((string) ($credential['principal']['facility_id'] ?? ''))) {
            $this->store()->forget($this->cacheKey($token));

            return null;
        }

        return [
            'user_id' => (string) $credential['principal']['user_id'],
            'facility_id' => (string) $credential['principal']['facility_id'],
        ];
    }

    private function cacheKey(string $token): string
    {
        return 'development-fixture-bearer:'.hash('sha256', $token);
    }

    private function store(): Repository
    {
        return Cache::store('file');
    }

    private function isUuidV7(string $value): bool
    {
        return preg_match(self::UUID_V7_PATTERN, $value) === 1;
    }
}