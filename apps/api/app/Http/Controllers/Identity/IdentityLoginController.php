<?php

namespace App\Http\Controllers\Identity;

use App\Http\Middleware\IdentityRequestBinding;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Identity\Domain\UserAccount;
use Modules\Identity\Exceptions\AuthenticationFailed;
use Modules\Identity\Features\Authentication\Contracts\AuthenticateUser;
use Modules\Identity\Features\Sessions\Contracts\SessionTransport;
use Modules\Identity\Http\IdentityApi;

final class IdentityLoginController
{
    public function __construct(private readonly AuthenticateUser $authentication) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = IdentityApi::correlationId($request);
        if ($correlationId === null) {
            return IdentityApi::problem(
                400,
                'invalid-correlation-id',
                'Bad Request',
                'X-Correlation-ID must be a lowercase UUIDv7.',
            );
        }

        $input = $request->json()->all();
        $validator = Validator::make($input, [
            'username' => ['required', 'string', 'min:1', 'max:128'],
            'password' => ['required', 'string', 'min:1', 'max:128'],
            'totp_code' => ['sometimes', 'string', 'regex:/\A[0-9]{6}\z/'],
        ]);
        if ($validator->fails()
            || array_diff(array_keys($input), ['username', 'password', 'totp_code']) !== []) {
            return IdentityApi::problem(
                400,
                'invalid-login',
                'Bad Request',
                'The login payload is invalid.',
                $correlationId,
            );
        }

        $credentials = $validator->validated();
        $source = 'identity-http:'.((string) ($request->ip() ?? 'unknown'));
        try {
            $transport = $this->authentication->authenticate(
                (string) $credentials['username'],
                (string) $credentials['password'],
                isset($credentials['totp_code']) ? (string) $credentials['totp_code'] : null,
                IdentityRequestBinding::metadata($request),
                $source,
            );
        } catch (AuthenticationFailed) {
            $retryAfter = $this->retryAfterSeconds($source, (string) $credentials['username']);
            if ($retryAfter !== null) {
                return IdentityApi::problem(
                    429,
                    'authentication-rate-limited',
                    'Too Many Requests',
                    'Too many authentication attempts. Try again later.',
                    $correlationId,
                )->header('Retry-After', (string) $retryAfter);
            }

            return IdentityApi::problem(
                401,
                'authentication-failed',
                'Unauthorized',
                'Authentication failed.',
                $correlationId,
            );
        } catch (QueryException) {
            return IdentityApi::problem(
                500,
                'identity-authentication-unavailable',
                'Internal Server Error',
                'Authentication is temporarily unavailable.',
                $correlationId,
            );
        }

        return $this->successfulResponse($transport, $correlationId);
    }

    private function successfulResponse(SessionTransport $transport, string $correlationId): JsonResponse
    {
        $response = response()->json([
            'data' => [
                'user_id' => $transport->userId,
                'expires_at' => $transport->expiresAt->format('Y-m-d\TH:i:s\Z'),
                'restricted' => $transport->restricted,
                'csrf_token' => $transport->csrfToken,
            ],
        ])->withHeaders([
            'X-Correlation-ID' => $correlationId,
            'X-CSRF-Token' => $transport->csrfToken,
        ]);

        return $response->withCookie($transport->cookie);
    }

    private function retryAfterSeconds(string $source, string $username): ?int
    {
        try {
            $normalized = UserAccount::normalizeUsername($username);
            $usernameHash = hash('sha256', $normalized);
            $sourceHash = hash('sha256', $source."\0".$normalized);
            $now = CarbonImmutable::now('UTC');
            $windowSeconds = max(1, (int) config('identity.pre_auth_throttle.window_seconds', 60));
            $sourceLimit = max(1, (int) config('identity.pre_auth_throttle.source_username_max_attempts', 4));
            $accountLimit = max(1, (int) config('identity.pre_auth_throttle.account_max_attempts', 20));

            $rows = DB::table('identity_auth_attempt_ledgers')
                ->whereIn('scope_hash', [$sourceHash, $usernameHash])
                ->get(['scope', 'window_started_at', 'attempt_count', 'blocked_until']);

            $earliestRetry = null;
            foreach ($rows as $row) {
                $retryAt = null;
                if ($row->blocked_until !== null) {
                    $blockedUntil = CarbonImmutable::parse($row->blocked_until, 'UTC');
                    if ($blockedUntil->greaterThan($now)) {
                        $retryAt = $blockedUntil;
                    }
                }
                if ($retryAt === null) {
                    $windowEnd = CarbonImmutable::parse($row->window_started_at, 'UTC')->addSeconds($windowSeconds);
                    $limit = $row->scope === 'account' ? $accountLimit : $sourceLimit;
                    if ($windowEnd->greaterThan($now) && (int) $row->attempt_count >= $limit) {
                        $retryAt = $windowEnd;
                    }
                }
                if ($retryAt === null) {
                    continue;
                }
                $delta = (int) ceil($retryAt->getTimestamp() - $now->getTimestamp());
                $seconds = max(1, $delta);
                if ($earliestRetry === null || $seconds < $earliestRetry) {
                    $earliestRetry = $seconds;
                }
            }
        } catch (QueryException) {
            return null;
        }

        return $earliestRetry;
    }
}
