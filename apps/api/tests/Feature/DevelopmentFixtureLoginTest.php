<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

    public function test_fixture_bearer_cannot_cross_the_session_only_work_record_path(): void
    {
        // The production binding must reject unknown fixture bearers; the
        // testing runtime allows the valid one only as a developer fallback.
        $unknownToken = str_repeat('a', 64);

        $this->withToken($unknownToken)
            ->getJson('/api/v1/work-records', $this->headers())
            ->assertUnauthorized();
    }

    public function test_identity_cookie_can_access_the_session_only_work_record_path(): void
    {
        Artisan::call('e2e:w1-2:seed');
        $fixture = json_decode(trim(Artisan::output()), true, 16, JSON_THROW_ON_ERROR);
        $login = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'security-test'])
            ->postJson('/api/v1/identity/login', [
                'username' => $fixture['identity_username'],
                'password' => $fixture['identity_password'],
            ], $this->headers())->assertOk();

        $this->assertCount(1, $login->headers->getCookies());
        $cookie = $login->headers->getCookies()[0]->getValue();
        $this->withUnencryptedCookie('cluster_identity_session', $cookie)->withCredentials()
            ->getJson('/api/v1/work-records', $this->headers())
            ->assertOk();
    }

    public function test_identity_cookie_reaches_the_session_only_authorization_path(): void
    {
        Artisan::call('e2e:w1-2:seed');
        $fixture = json_decode(trim(Artisan::output()), true, 16, JSON_THROW_ON_ERROR);
        $login = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'security-test'])
            ->postJson('/api/v1/identity/login', [
                'username' => $fixture['identity_username'],
                'password' => $fixture['identity_password'],
            ], $this->headers())->assertOk();

        $this->assertCount(1, $login->headers->getCookies());
        $this->withUnencryptedCookie('cluster_identity_session', $login->headers->getCookies()[0]->getValue())->withCredentials()
            ->getJson('/api/v1/authorization/bootstrap', $this->headers())
            ->assertStatus(200);
    }

    public function test_cookie_session_is_used_across_web_used_r1_route_groups(): void
    {
        [$cookie, $csrf] = $this->identityCookie();
        $headers = [...$this->headers(), 'X-CSRF-Token' => $csrf];

        foreach ([
            '/api/v1/notifications',
            '/api/v1/search?query=journey',
            '/api/v1/reports',
            '/api/v1/dashboards',
            '/api/v1/organization/cluster',
            '/api/v1/identity/accounts',
            '/api/v1/tasks',
            '/api/v1/work-definitions',
            '/api/v1/workflow/definitions',
            '/api/v1/documents',
        ] as $uri) {
            $response = $this->withUnencryptedCookie('cluster_identity_session', $cookie)->withCredentials()
                ->getJson($uri, $headers);

            $this->assertNotSame(401, $response->status(), $uri.' must use the Identity session path.');
            $this->assertNotSame(403, $response->status(), $uri.' must use the Identity session path.');
        }
    }

    public function test_cookie_mutation_requires_csrf_and_accepts_valid_csrf_proof(): void
    {
        [$cookie, $csrf] = $this->identityCookie();
        $uri = '/api/v1/organization/cluster';

        $this->withUnencryptedCookie('cluster_identity_session', $cookie)->withCredentials()
            ->postJson($uri, [], $this->headers())
            ->assertForbidden()
            ->assertJsonPath('type', 'https://cluster.example/problems/csrf-failed');

        $response = $this->withUnencryptedCookie('cluster_identity_session', $cookie)->withCredentials()
            ->postJson($uri, [], [...$this->headers(), 'X-CSRF-Token' => $csrf]);

        $this->assertNotSame('https://cluster.example/problems/csrf-failed', $response->json('type'));
    }

    public function test_legacy_fixture_bearer_cannot_cross_remaining_r1_route_groups(): void
    {
        // The production binding must reject unknown fixture bearers; the
        // testing runtime allows the valid one only as a developer fallback.
        $unknownToken = str_repeat('a', 64);

        foreach ([
            '/api/v1/notifications',
            '/api/v1/search?query=journey',
            '/api/v1/reports',
            '/api/v1/dashboards',
            '/api/v1/organization/cluster',
            '/api/v1/identity/accounts',
            '/api/v1/tasks',
            '/api/v1/work-definitions',
            '/api/v1/workflow/definitions',
            '/api/v1/documents',
        ] as $uri) {
            $this->withToken($unknownToken)
                ->getJson($uri, $this->headers())
                ->assertUnauthorized();
        }
    }

    public function test_cookie_session_and_csrf_cover_final_r1_route_groups(): void
    {
        [$cookie, $csrf] = $this->identityCookie();
        $headers = [...$this->headers(), 'X-CSRF-Token' => $csrf];

        foreach ([
            '/api/v1/work-definitions',
            '/api/v1/workflow/definitions',
            '/api/v1/workflow/instances',
            '/api/v1/tasks',
            '/api/v1/documents',
            '/api/v1/reports',
            '/api/v1/dashboards',
        ] as $uri) {
            $response = $this->withUnencryptedCookie('cluster_identity_session', $cookie)->withCredentials()
                ->getJson($uri, $headers);
            $this->assertNotSame(401, $response->status(), $uri.' must accept the server session.');
        }

        foreach ([
            '/api/v1/work-definitions',
            '/api/v1/workflow/definitions',
            '/api/v1/workflow/instances',
            '/api/v1/tasks',
            '/api/v1/documents',
        ] as $uri) {
            $this->withUnencryptedCookie('cluster_identity_session', $cookie)->withCredentials()
                ->postJson($uri, [], $this->headers())
                ->assertForbidden()
                ->assertJsonPath('type', 'https://cluster.example/problems/csrf-failed');

            $response = $this->withUnencryptedCookie('cluster_identity_session', $cookie)->withCredentials()
                ->postJson($uri, [], $headers);
            $this->assertNotSame('https://cluster.example/problems/csrf-failed', $response->json('type'), $uri.' rejected valid CSRF.');
        }

        $token = $this->loginToken();
        foreach ([
            '/api/v1/work-definitions',
            '/api/v1/workflow/definitions',
            '/api/v1/workflow/instances',
            '/api/v1/tasks',
            '/api/v1/documents',
            '/api/v1/reports',
            '/api/v1/dashboards',
        ] as $uri) {
            $this->withToken($token)->getJson($uri, $this->headers())->assertUnauthorized();
        }
    }

    /** @return array{0: string, 1: string} */
    private function identityCookie(): array
    {
        Artisan::call('e2e:w1-2:seed');
        $fixture = json_decode(trim(Artisan::output()), true, 16, JSON_THROW_ON_ERROR);
        $login = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'security-test'])
            ->postJson('/api/v1/identity/login', [
                'username' => $fixture['identity_username'],
                'password' => $fixture['identity_password'],
            ], $this->headers())->assertOk();

        $this->assertCount(1, $login->headers->getCookies());

        return [
            $login->headers->getCookies()[0]->getValue(),
            (string) $login->json('data.csrf_token'),
        ];
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
