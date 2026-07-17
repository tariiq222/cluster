<?php

namespace Modules\Identity\Features\ResolveDevelopmentFixturePrincipal\Http;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

final class DevelopmentFixturePrincipalResolver implements ResolveDevelopmentFixturePrincipal
{
    private const TOKEN_TTL_MINUTES = 120;

    /**
     * @param  array{user_id: string, facility_id: string}  $principal
     * @return array{access_token: string, expires_at: string}
     */
    public function issue(array $principal): array
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $token = Str::random(64);
        $expiresAt = now()->addMinutes(self::TOKEN_TTL_MINUTES);
        $this->store()->put($this->cacheKey($token), [
            'principal' => $principal,
            'expires_at' => $expiresAt->getTimestamp(),
        ], $expiresAt);

        return [
            'access_token' => $token,
            'expires_at' => $expiresAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    /**
     * @return array{user_id: string, facility_id: string}|null
     */
    public function resolve(Request $request): ?array
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $token = $request->bearerToken();
        if (! is_string($token) || preg_match('/\A[A-Za-z0-9]{64}\z/', $token) !== 1) {
            return null;
        }

        $cacheKey = $this->cacheKey($token);
        $credential = $this->store()->get($cacheKey);
        if (! is_array($credential)
            || ! is_int($credential['expires_at'] ?? null)
            || $credential['expires_at'] <= now()->getTimestamp()
            || ! is_array($credential['principal'] ?? null)
            || ! $this->isUuidV7($credential['principal']['user_id'] ?? null)
            || ! $this->isUuidV7($credential['principal']['facility_id'] ?? null)) {
            $this->store()->forget($cacheKey);

            return null;
        }

        return [
            'user_id' => $credential['principal']['user_id'],
            'facility_id' => $credential['principal']['facility_id'],
        ];
    }

    private function cacheKey(string $token): string
    {
        return 'development-fixture-bearer:'.hash('sha256', $token);
    }

    private function isUuidV7(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1;
    }

    private function store(): Repository
    {
        return Cache::store('file');
    }
}
