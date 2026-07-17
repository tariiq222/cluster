<?php

namespace Modules\Identity\Features\DevelopmentFixtureLogin\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

final class DevelopmentFixtureLoginController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $input = $request->all();
        $validator = Validator::make($input, [
            'username' => ['required', 'string', 'min:1', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:255'],
        ]);
        if ($validator->fails()
            || array_diff(array_keys($input), ['username', 'password']) !== []
            || ! $this->isUuidV7($request->header('X-Correlation-ID'))) {
            return $this->invalidRequestResponse($request);
        }
        $credentials = $validator->validated();

        $account = DB::table('identity_development_fixture_accounts')
            ->where('username', $credentials['username'])
            ->first();

        if ($account === null || ! Hash::check($credentials['password'], $account->password_hash)) {
            return $this->invalidCredentialsResponse($request);
        }

        $principal = [
            'user_id' => $account->id,
            'facility_id' => $account->facility_id,
        ];

        $request->session()->regenerate();
        $request->session()->put('development_fixture_principal', $principal);
        $credential = $this->principalResolver->issue($principal);

        return response()->json([
            'data' => [
                'access_token' => $credential['access_token'],
                'token_type' => 'Bearer',
                'expires_at' => $credential['expires_at'],
                'facility' => $account->facility_id === '018f6f7d-0c00-7000-8000-000000000011' ? 'facility-a' : 'facility-b',
                'principal' => $principal,
            ],
        ])->withHeaders($this->correlationHeader($request));
    }

    private function invalidCredentialsResponse(Request $request): JsonResponse
    {
        return response()->json([
            'type' => 'https://cluster.example/problems/invalid-credentials',
            'title' => 'Unauthorized',
            'status' => 401,
            'detail' => 'بيانات الاعتماد غير صالحة.',
        ], 401)->withHeaders([
            ...$this->correlationHeader($request),
            'Content-Type' => 'application/problem+json',
        ]);
    }

    private function invalidRequestResponse(Request $request): JsonResponse
    {
        return response()->json([
            'type' => 'https://cluster.example/problems/invalid-request',
            'title' => 'Bad Request',
            'status' => 400,
            'detail' => 'تعذر قبول طلب تسجيل الدخول.',
        ], 400)->withHeaders([
            ...$this->correlationHeader($request),
            'Content-Type' => 'application/problem+json',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function correlationHeader(Request $request): array
    {
        $correlationId = $request->header('X-Correlation-ID');

        return [
            'X-Correlation-ID' => $this->isUuidV7($correlationId)
                ? $correlationId
                : Str::uuid7()->toString(),
        ];
    }

    private function isUuidV7(mixed $value): bool
    {
        return is_string($value)
            && preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                $value,
            ) === 1;
    }
}
