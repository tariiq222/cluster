<?php

namespace Modules\Identity\Features\Authentication\Http;

use App\Http\Middleware\IdentityRequestBinding;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Identity\Domain\UserAccount;
use Modules\Identity\Exceptions\AuthenticationFailed;
use Modules\Identity\Features\Authentication\Contracts\AuthenticateUser;
use Modules\Identity\Features\Authentication\Contracts\PreAuthThrottle;
use Modules\Identity\Features\Sessions\Contracts\SessionTransport;
use Modules\Identity\Http\IdentityApi;

final class IdentityLoginController
{
    public function __construct(
        private readonly AuthenticateUser $authentication,
        private readonly PreAuthThrottle $throttle,
    ) {}

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
            $retryAfter = $this->throttle->retryAfterSeconds(
                $source,
                UserAccount::normalizeUsername((string) $credentials['username']),
            );
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
}
