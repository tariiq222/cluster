<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DevelopmentFixtureLoginTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000101';

    public function test_fixture_accounts_receive_principals_for_their_own_facilities(): void
    {
        $accountA = $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'fixture-password-a',
        ], $this->headers());

        $accountA->assertOk()
            ->assertJsonPath('data.facility', 'facility-a')
            ->assertJsonPath('data.principal.facility_id', '018f6f7d-0c00-7000-8000-000000000011');
        $this->assertMatchesRegularExpression(
            '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z\z/',
            (string) $accountA->json('data.expires_at'),
        );
        $tokenA = $accountA->json('data.access_token');
        $this->assertIsString($tokenA);
        $this->assertTrue(Cache::store('file')->has('development-fixture-bearer:'.hash('sha256', $tokenA)));
        $this->assertFalse(Cache::store('file')->has('development-fixture-bearer:'.$tokenA));
        $this->assertSame(
            '018f6f7d-0c00-7000-8000-000000000011',
            $this->app['session']->get('development_fixture_principal.facility_id'),
        );

        $accountB = $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-b',
            'password' => 'fixture-password-b',
        ], $this->headers());

        $accountB->assertOk()
            ->assertJsonPath('data.facility', 'facility-b')
            ->assertJsonPath('data.principal.facility_id', '018f6f7d-0c00-7000-8000-000000000012');
        $this->assertNotSame($tokenA, $accountB->json('data.access_token'));
    }

    public function test_invalid_credentials_receive_the_same_generic_unauthorized_response(): void
    {
        $unknownAccount = $this->postJson('/api/v1/auth/login', [
            'username' => 'unknown-account',
            'password' => 'fixture-password-a',
        ], $this->headers());

        $invalidPassword = $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'incorrect-password',
        ], $this->headers());

        $unknownAccount->assertUnauthorized()
            ->assertJsonPath('detail', 'بيانات الاعتماد غير صالحة.')
            ->assertJsonMissingPath('username');

        $invalidPassword->assertUnauthorized()
            ->assertJsonPath('detail', 'بيانات الاعتماد غير صالحة.')
            ->assertJsonMissingPath('username');
    }

    public function test_login_rejects_contract_invalid_requests_with_safe_correlation(): void
    {
        $shortPassword = $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'short',
        ], $this->headers());

        $shortPassword->assertBadRequest()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('X-Correlation-ID', self::CORRELATION_ID)
            ->assertJsonPath('status', 400)
            ->assertJsonMissingPath('errors');

        $missingCorrelation = $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'fixture-password-a',
        ]);

        $missingCorrelation->assertBadRequest()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 400);
        $this->assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            (string) $missingCorrelation->headers->get('X-Correlation-ID'),
        );

        $unknownField = $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'fixture-password-a',
            'role' => 'admin',
        ], $this->headers());

        $unknownField->assertBadRequest()->assertJsonMissingPath('role');
    }

    public function test_expired_fixture_bearer_state_is_rejected_and_removed(): void
    {
        $token = $this->loginToken();
        $cacheKey = $this->cacheKey($token);
        Cache::store('file')->put($cacheKey, [
            'principal' => [
                'user_id' => '018f6f7d-0c00-7000-8000-000000000021',
                'facility_id' => '018f6f7d-0c00-7000-8000-000000000011',
            ],
            'expires_at' => now()->subSecond()->getTimestamp(),
        ], now()->addMinute());

        $this->withToken($token)
            ->getJson('/api/v1/work-records', $this->headers())
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json');
        $this->assertFalse(Cache::store('file')->has($cacheKey));
    }

    public function test_malformed_fixture_bearer_state_is_rejected_and_removed(): void
    {
        $token = $this->loginToken();
        $cacheKey = $this->cacheKey($token);
        $malformedStates = [
            ['principal' => 'not-an-array', 'expires_at' => now()->addMinute()->getTimestamp()],
            ['principal' => ['user_id' => 'not-a-uuid', 'facility_id' => 'also-invalid'], 'expires_at' => now()->addMinute()->getTimestamp()],
            ['principal' => ['user_id' => '018f6f7d-0c00-7000-8000-000000000021', 'facility_id' => '018f6f7d-0c00-7000-8000-000000000011'], 'expires_at' => 'not-an-integer'],
        ];

        foreach ($malformedStates as $state) {
            Cache::store('file')->put($cacheKey, $state, now()->addMinute());
            $this->withToken($token)
                ->getJson('/api/v1/work-records', $this->headers())
                ->assertUnauthorized();
            $this->assertFalse(Cache::store('file')->has($cacheKey));
        }
    }

    private function loginToken(): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'fixture-password-a',
        ], $this->headers())->assertOk()->json('data.access_token');
    }

    private function cacheKey(string $token): string
    {
        return 'development-fixture-bearer:'.hash('sha256', $token);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['X-Correlation-ID' => self::CORRELATION_ID];
    }
}
