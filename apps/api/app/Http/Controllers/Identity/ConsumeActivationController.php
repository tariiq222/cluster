<?php

namespace App\Http\Controllers\Identity;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Identity\Exceptions\AuthenticationFailed;
use Modules\Identity\Exceptions\WeakPassword;
use Modules\Identity\Features\Activation\Handler\ActivationHandler;
use Modules\Identity\Features\Totp\Handler\TotpHandler;
use Modules\Identity\Http\IdentityApi;
use stdClass;

final class ConsumeActivationController
{
    public function __construct(
        private readonly ActivationHandler $activation,
        private readonly TotpHandler $totp,
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
            'token' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
            'password' => [
                'required',
                'string',
                'min:'.max(1, (int) config('identity.password.min_length', 14)),
                'max:'.max(1, (int) config('identity.password.max_length', 128)),
            ],
            'totp_code' => ['sometimes', 'string', 'regex:/\A[0-9]{6}\z/'],
        ]);
        if ($validator->fails()
            || array_diff(array_keys($input), ['token', 'password', 'totp_code']) !== []) {
            return IdentityApi::problem(
                400,
                'invalid-activation',
                'Bad Request',
                'The activation payload is invalid.',
                $correlationId,
            );
        }

        $validated = $validator->validated();
        try {
            DB::transaction(function () use ($validated): void {
                $activation = DB::table('identity_activation_tokens')
                    ->where('token_hash', hash('sha256', (string) $validated['token']))
                    ->lockForUpdate()
                    ->first(['user_id']);
                if ($activation instanceof stdClass) {
                    $user = DB::table('users')->where('id', $activation->user_id)->first(['is_admin']);
                    if ($user instanceof stdClass && (bool) $user->is_admin) {
                        $totpCode = isset($validated['totp_code']) ? (string) $validated['totp_code'] : '';
                        if (! $this->totp->verify((string) $activation->user_id, $totpCode)) {
                            throw new AuthenticationFailed;
                        }
                    }
                }

                $this->activation->activate((string) $validated['token'], (string) $validated['password']);
            });
        } catch (WeakPassword) {
            return IdentityApi::problem(
                422,
                'weak-password',
                'Unprocessable Content',
                'The password does not satisfy the current policy.',
                $correlationId,
            );
        } catch (AuthenticationFailed|DomainException) {
            return IdentityApi::problem(
                401,
                'activation-failed',
                'Unauthorized',
                'The activation could not be completed.',
                $correlationId,
            );
        } catch (QueryException) {
            return IdentityApi::problem(
                500,
                'identity-activation-unavailable',
                'Internal Server Error',
                'Activation is temporarily unavailable.',
                $correlationId,
            );
        }

        return response()->json(null, 204)->header('X-Correlation-ID', $correlationId);
    }
}
