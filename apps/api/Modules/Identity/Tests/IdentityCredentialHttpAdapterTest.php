<?php

namespace Modules\Identity\Tests;

use App\Http\Middleware\IdentityCsrfMiddleware;
use App\Http\Middleware\IdentitySessionMiddleware;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Identity\Contracts\PrincipalContext;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Modules\Identity\Features\Activation\Contracts\IssueActivationToken;
use Modules\Identity\Features\Activation\Handler\ActivationHandler;
use Modules\Identity\Features\Activation\Http\ConsumeActivationController;
use Modules\Identity\Features\Activation\Http\IssueActivationController;
use Modules\Identity\Features\Authentication\Contracts\AuthenticateUser;
use Modules\Identity\Features\Authentication\Handler\AuthenticationHandler;
use Modules\Identity\Features\Authentication\Http\IdentityLoginController;
use Modules\Identity\Features\Authentication\Http\IdentityLogoutController;
use Modules\Identity\Features\Credentials\Contracts\ChangePassword;
use Modules\Identity\Features\Credentials\Handler\CredentialHandler;
use Modules\Identity\Features\Credentials\Http\ChangePasswordController;
use Modules\Identity\Features\Sessions\Contracts\ResolveSession;
use Modules\Identity\Features\Sessions\Handler\SessionHandler;
use Modules\Identity\Features\Sessions\Http\GetCurrentIdentityController;
use Tests\TestCase;

class IdentityCredentialHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000801';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', [
            '--path' => 'Modules/Identity/Infrastructure/Persistence/Migrations/ZAddIdentityCredentialCoreTables.php',
        ])->assertSuccessful();
        $this->app->bind(AuthenticateUser::class, AuthenticationHandler::class);
        $this->app->bind(IssueActivationToken::class, ActivationHandler::class);
        $this->app->bind(ChangePassword::class, CredentialHandler::class);
        $this->app->bind(ResolveSession::class, SessionHandler::class);
        Route::post('/_test/identity/login', IdentityLoginController::class);
        Route::post('/_test/identity/activation', ConsumeActivationController::class);
        Route::middleware(IdentitySessionMiddleware::class)->get('/_test/identity/me', GetCurrentIdentityController::class);
        Route::middleware([IdentitySessionMiddleware::class, IdentityCsrfMiddleware::class])->group(function (): void {
            Route::post('/_test/identity/logout', IdentityLogoutController::class);
            Route::post('/_test/identity/password', ChangePasswordController::class);
            Route::post('/_test/identity/accounts/{accountId}/activation', IssueActivationController::class);
        });
        $this->withCredentials();
    }

    public function test_activation_consumption_and_login_never_return_a_session_token(): void
    {
        $userId = $this->createUser('http.login');
        $activation = $this->app->make(ActivationHandler::class)->issue($userId);

        $this->postJson('/_test/identity/activation', [
            'token' => $activation['token'],
            'password' => 'A secure activation phrase 2026!',
        ], $this->headers())->assertNoContent();

        $login = $this->postJson('/_test/identity/login', [
            'username' => 'http.login',
            'password' => 'A secure activation phrase 2026!',
        ], $this->headers())->assertOk();

        $login->assertJsonMissingPath('data.access_token')
            ->assertJsonMissingPath('data.refresh_token')
            ->assertJsonMissingPath('data.session_token')
            ->assertJsonPath('data.csrf_token', $login->headers->get('X-CSRF-Token'))
            ->assertHeader('X-Correlation-ID', self::CORRELATION_ID);
        $cookie = $login->headers->getCookies()[0];
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertNotSame($cookie->getValue(), DB::table('identity_sessions')->value('token_hash'));
    }

    public function test_session_bootstrap_and_csrf_protect_logout_without_mutating_on_failure(): void
    {
        [$cookie, $csrf, $userId] = $this->loginUser('http.logout');

        $this->withUnencryptedCookie($this->cookieName(), $cookie)->getJson('/_test/identity/me', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.principal.user_id', $userId)
            ->assertJsonPath('data.account.username', 'http.logout');

        $this->withUnencryptedCookie($this->cookieName(), $cookie)->postJson('/_test/identity/logout', [], [
            ...$this->headers(),
            'Idempotency-Key' => 'logout-http',
            'X-CSRF-Token' => 'wrong',
        ])->assertForbidden();
        $this->assertNull(DB::table('identity_sessions')->where('token_hash', hash('sha256', $cookie))->value('revoked_at'));

        $this->withUnencryptedCookie($this->cookieName(), $cookie)->postJson('/_test/identity/logout', [], [
            ...$this->headers(),
            'Idempotency-Key' => 'logout-http',
            'X-CSRF-Token' => $csrf,
        ])->assertNoContent();
        $this->assertNotNull(DB::table('identity_sessions')->where('token_hash', hash('sha256', $cookie))->value('revoked_at'));
    }

    public function test_current_identity_is_an_authenticated_self_bound_bootstrap_and_records_sensitive_access(): void
    {
        [$cookie, , $userId] = $this->loginUser('http.current.identity');

        $response = $this->withUnencryptedCookie($this->cookieName(), $cookie)
            ->getJson('/api/v1/identity/me?user_id=018f6f7d-0c00-7000-8000-000000000999', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.principal.user_id', $userId)
            ->assertJsonPath('data.account.username', 'http.current.identity');

        $response->assertJsonMissingPath('subject_id');
        $this->assertDatabaseHas('sensitive_access_events', [
            'actor_user_id' => $userId,
            'original_actor_user_id' => $userId,
            'resource_type' => 'identity_current_projection',
            'resource_id' => $userId,
            'action' => 'read',
            'classification_code' => 'confidential',
            'correlation_id' => self::CORRELATION_ID,
        ]);

        $this->withUnencryptedCookie($this->cookieName(), '')->getJson('/api/v1/identity/me', $this->headers())
            ->assertUnauthorized()
            ->assertJsonPath('type', 'https://cluster.example/problems/authentication-required')
            ->assertJsonMissingPath('data');
        $this->getJson('/api/v1/identity/me/018f6f7d-0c00-7000-8000-000000000999', $this->headers())
            ->assertNotFound();
    }

    public function test_identity_me_and_me_are_intentionally_non_equivalent_production_projections(): void
    {
        [$cookie, , $userId] = $this->loginUser('http.distinct.projections');
        $this->app->instance(ResolvePrincipalContext::class, new class($userId) implements ResolvePrincipalContext
        {
            public function __construct(private readonly string $userId) {}

            public function resolve(Request $request): PrincipalContext
            {
                return new PrincipalContext(
                    userId: $this->userId,
                    personId: null,
                    accountStatus: 'active',
                    clusterIds: ['cluster-self-read'],
                    facilityIds: [],
                    organizationUnitIds: ['unit-self-read'],
                    primaryOrganizationUnitId: 'unit-self-read',
                    selectedScope: null,
                    sessionRestricted: false,
                );
            }

            public function resolveSelectedScope(Request $request): ?array
            {
                return null;
            }

            public function persistSelectedScope(Request $request, string $scopeType, string $scopeId): void {}
        });

        $identity = $this->withUnencryptedCookie($this->cookieName(), $cookie)
            ->getJson('/api/v1/identity/me', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.principal.user_id', $userId)
            ->assertJsonPath('data.account.username', 'http.distinct.projections')
            ->assertJsonMissingPath('subject_id');

        $principal = $this->withUnencryptedCookie($this->cookieName(), $cookie)
            ->getJson('/api/v1/me', $this->headers())
            ->assertOk()
            ->assertJsonPath('subject_id', $userId)
            ->assertJsonPath('tenant_id', 'cluster-self-read')
            ->assertJsonPath('organization_unit_ids.0', 'unit-self-read')
            ->assertJsonMissingPath('data.account');

        $this->assertNotSame($identity->json(), $principal->json());
    }

    public function test_anonymous_activation_and_worker_document_routes_validate_correlation_without_session_authentication(): void
    {
        $headers = ['X-Correlation-ID' => 'malformed'];
        $versionId = '018f6f7d-0c00-7000-8000-000000000777';

        foreach ([
            '/api/v1/identity/activation',
            '/api/v1/internal/documents/versions/'.$versionId.'/scan',
            '/api/v1/internal/documents/versions/'.$versionId.'/reconcile-promotion',
        ] as $path) {
            $this->postJson($path, [], $headers)
                ->assertStatus(400)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('type', 'https://cluster.example/problems/invalid-correlation-id');
        }
    }

    public function test_login_surfaces_the_core_rate_limit_as_a_generic_429(): void
    {
        config([
            'identity.pre_auth_throttle.source_username_max_attempts' => 1,
            'identity.pre_auth_throttle.account_max_attempts' => 100,
            'identity.pre_auth_throttle.lock_durations_minutes' => [15, 30, 60, 120],
        ]);

        $response = $this->postJson('/_test/identity/login', [
            'username' => 'rate-limited.user',
            'password' => 'wrong password phrase',
        ], $this->headers());
        $response->assertTooManyRequests()
            ->assertJsonPath('type', 'https://cluster.example/problems/authentication-rate-limited');

        $blockedUntil = DB::table('identity_auth_attempt_ledgers')
            ->where('scope', 'source_username')
            ->value('blocked_until');
        $this->assertNotNull($blockedUntil);
        $expectedRetry = max(1, (int) ceil(
            CarbonImmutable::parse($blockedUntil, 'UTC')->getTimestamp() - CarbonImmutable::now('UTC')->getTimestamp()
        ));
        $this->assertSame((string) $expectedRetry, (string) $response->headers->get('Retry-After'));
    }

    public function test_issue_activation_is_authorized_idempotent_and_does_not_store_raw_token(): void
    {
        [$cookie, $csrf] = $this->loginUser('http.issuer', '018f6f7d-0c00-7000-8000-000000000021');
        $accountId = $this->createUser('http.pending');

        $headers = [
            ...$this->headers(),
            'Idempotency-Key' => 'activation-http',
            'X-CSRF-Token' => $csrf,
        ];
        $first = $this->withUnencryptedCookie($this->cookieName(), $cookie)->postJson('/_test/identity/accounts/'.$accountId.'/activation', [], $headers)
            ->assertStatus(202)
            ->assertJsonPath('account_id', $accountId);
        $this->assertJsonDoesNotContainToken($first->json());
        $this->assertJsonDoesNotContainToken(json_decode((string) DB::table('identity_idempotency_keys')->value('response_payload'), true));

        $this->withUnencryptedCookie($this->cookieName(), $cookie)->postJson('/_test/identity/accounts/'.$accountId.'/activation', [], $headers)
            ->assertStatus(202)
            ->assertJsonPath('account_id', $accountId);
        $this->assertDatabaseCount('identity_activation_tokens', 2);
    }

    public function test_change_password_accepts_a_restricted_or_full_session_and_revokes_it(): void
    {
        [$cookie, $csrf] = $this->loginUser('http.password');
        $this->withUnencryptedCookie($this->cookieName(), $cookie)->postJson('/_test/identity/password', [
            'current_password' => 'A secure activation phrase 2026!',
            'new_password' => 'A completely new phrase 2026!',
        ], [
            ...$this->headers(),
            'X-CSRF-Token' => $csrf,
        ])->assertNoContent();

        $this->assertDatabaseMissing('identity_sessions', [
            'token_hash' => hash('sha256', $cookie),
            'revoked_at' => null,
        ]);
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function loginUser(string $username, ?string $userId = null): array
    {
        $userId = $this->createUser($username, $userId);
        $activation = $this->app->make(ActivationHandler::class)->issue($userId);
        $this->postJson('/_test/identity/activation', [
            'token' => $activation['token'],
            'password' => 'A secure activation phrase 2026!',
        ], $this->headers())->assertNoContent();
        $response = $this->postJson('/_test/identity/login', [
            'username' => $username,
            'password' => 'A secure activation phrase 2026!',
        ], $this->headers())->assertOk();

        return [
            $response->headers->getCookies()[0]->getValue(),
            (string) $response->headers->get('X-CSRF-Token'),
            $userId,
        ];
    }

    private function createUser(string $username, ?string $userId = null): string
    {
        $id = $userId ?? Str::uuid7()->toString();
        DB::table('users')->insert([
            'id' => $id,
            'username' => $username,
            'person_id' => null,
            'person_version' => null,
            'display_name_ar' => 'مستخدم اختبار HTTP',
            'display_name_en' => 'HTTP Test User',
            'status' => 'pending',
            'must_change_password' => true,
            'password_version' => 1,
            'last_login_at' => null,
            'failed_login_count' => 0,
            'lockout_level' => 0,
            'locked_until' => null,
            'lock_version' => 1,
            'is_admin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Correlation-ID' => self::CORRELATION_ID];
    }

    private function cookieName(): string
    {
        return (string) config('identity.session.cookie', 'cluster_identity_session');
    }

    private function assertJsonDoesNotContainToken(mixed $value): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('token', strtolower($json));
    }
}
