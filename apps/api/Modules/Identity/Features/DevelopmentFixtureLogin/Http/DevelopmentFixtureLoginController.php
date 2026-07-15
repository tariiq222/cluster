<?php

namespace Modules\Identity\Features\DevelopmentFixtureLogin\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class DevelopmentFixtureLoginController
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

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

        return response()->json([
            'data' => [
                'access_token' => Str::random(64),
                'token_type' => 'Bearer',
                'expires_at' => now()->addMinutes(120)->utc()->toIso8601String(),
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
        ], 401)->withHeaders($this->correlationHeader($request));
    }

    /**
     * @return array<string, string>
     */
    private function correlationHeader(Request $request): array
    {
        $correlationId = $request->header('X-Correlation-ID');

        return is_string($correlationId) && $correlationId !== ''
            ? ['X-Correlation-ID' => $correlationId]
            : [];
    }
}
