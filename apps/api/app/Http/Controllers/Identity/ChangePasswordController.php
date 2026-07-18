<?php

namespace App\Http\Controllers\Identity;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Identity\Exceptions\AuthenticationFailed;
use Modules\Identity\Exceptions\WeakPassword;
use Modules\Identity\Features\Credentials\Contracts\ChangePassword;
use Modules\Identity\Http\IdentityApi;
use Symfony\Component\HttpFoundation\Cookie;

final class ChangePasswordController
{
    public function __construct(private readonly ChangePassword $passwords) {}

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

        $principal = $request->attributes->get('identity.principal');
        if (! is_array($principal) || ! is_string($principal['user_id'] ?? null)) {
            return IdentityApi::problem(
                401,
                'authentication-required',
                'Unauthorized',
                'Authentication is required.',
                $correlationId,
            );
        }

        $input = $request->json()->all();
        $validator = Validator::make($input, [
            'current_password' => ['required', 'string', 'min:1', 'max:128'],
            'new_password' => [
                'required',
                'string',
                'min:'.max(1, (int) config('identity.password.min_length', 14)),
                'max:'.max(1, (int) config('identity.password.max_length', 128)),
            ],
            'new_password_confirmation' => ['sometimes', 'same:new_password'],
        ]);
        if ($validator->fails()
            || array_diff(array_keys($input), ['current_password', 'new_password', 'new_password_confirmation']) !== []) {
            return IdentityApi::problem(
                400,
                'invalid-password-change',
                'Bad Request',
                'The password change payload is invalid.',
                $correlationId,
            );
        }

        $validated = $validator->validated();
        try {
            $this->passwords->change(
                $principal['user_id'],
                (string) $validated['current_password'],
                (string) $validated['new_password'],
            );
        } catch (WeakPassword) {
            return IdentityApi::problem(
                422,
                'weak-password',
                'Unprocessable Content',
                'The password does not satisfy the current policy.',
                $correlationId,
            );
        } catch (AuthenticationFailed) {
            return IdentityApi::problem(
                401,
                'authentication-failed',
                'Unauthorized',
                'The current password is invalid.',
                $correlationId,
            );
        }

        return response()->json(null, 204)
            ->header('X-Correlation-ID', $correlationId)
            ->withCookie(new Cookie(
                (string) config('identity.session.cookie', 'cluster_identity_session'),
                '',
                now()->subYear(),
                (string) config('identity.session.path', '/'),
                null,
                (bool) config('identity.session.secure', true),
                (bool) config('identity.session.http_only', true),
                false,
                (string) config('identity.session.same_site', 'lax'),
            ));
    }
}
