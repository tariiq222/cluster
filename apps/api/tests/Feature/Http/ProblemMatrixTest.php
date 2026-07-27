<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\PrincipalContext;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Tests\TestCase;

/**
 * Response-matrix test for the `application/problem+json` envelope contract.
 *
 * Each row exercises a real production endpoint under the production middleware
 * stack (IdentitySessionMiddleware + RequireIdentitySessionPrincipal +
 * IdentityCsrfMiddleware where applicable) and asserts the canonical shape:
 * status code, `Content-Type: application/problem+json`, `status` body, a
 * non-empty `correlation_id` body field that matches the `X-Correlation-ID`
 * response header, and a non-empty `detail` body field. The matrix also
 * asserts no raw exception / stack-trace keys leak into the body.
 */
final class ProblemMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const USER_ID = '018f6f7d-0c00-7000-8000-000000000021';

    private const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000011';

    private const CORRELATION = '018f6f7d-0c00-7000-8000-000000000001';

    private const SECONDARY_CORRELATION = '018f6f7d-0c00-7000-8000-000000000002';

    private string $cookie = '';

    private string $csrf = '';

    private string $identityUsername = '';

    private string $identityPassword = '';

    private string $sessionId = '';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('e2e:w1-2:seed');
        $fixture = json_decode(trim(Artisan::output()), true, 8, JSON_THROW_ON_ERROR);
        $this->identityUsername = (string) $fixture['identity_username'];
        $this->identityPassword = (string) $fixture['identity_password'];

        $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'problem-matrix-test',
        ]);
        $login = $this->postJson('/api/v1/identity/login', [
            'username' => $this->identityUsername,
            'password' => $this->identityPassword,
        ], ['X-Correlation-ID' => self::CORRELATION])->assertOk();
        $this->cookie = (string) $login->headers->getCookies()[0]->getValue();
        $this->csrf = (string) $login->json('data.csrf_token');

        $this->sessionId = (string) DB::table('identity_sessions')
            ->where('user_id', self::USER_ID)
            ->orderByDesc('issued_at')
            ->value('id');

        // Make the freshly issued session metadata carry the expected
        // `scope_version` baseline so the 412 row can assert against the
        // default scope version emitted by SelectMyScopeController.
        $row = DB::table('identity_sessions')->where('id', $this->sessionId)->value('metadata');
        $metadata = is_string($row) && $row !== '' ? (array) json_decode($row, true, 16, JSON_THROW_ON_ERROR) : [];
        $metadata['scope_version'] = 1;
        DB::table('identity_sessions')->where('id', $this->sessionId)->update([
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);

        // Bind a fake ResolvePrincipalContext so the Identity scope-selection
        // controller treats the test principal as holding the seeded facility
        // scope. The production resolver would otherwise need an Organization
        // scope-facts walk that this matrix test is not exercising.
        $this->app->bind(ResolvePrincipalContext::class, static fn (): ResolvePrincipalContext => new MatrixPrincipalContext(
            self::USER_ID,
            self::FACILITY_ID,
            facilityIds: [self::FACILITY_ID, '018f6f7d-0c00-7000-8000-000000000012'],
            clusterIds: ['018f6f7d-0c00-7000-8000-000000000009'],
        ));
    }

    public function test_401_no_session_from_identity_session_middleware_via_search(): void
    {
        // Anonymous request — IdentitySessionMiddleware refuses the request
        // and emits the canonical problem envelope via IdentityApi::problem,
        // which delegates to ProblemEnvelope::make and forwards the request
        // correlation id into both the body field and the response header.
        $response = $this->getJson('/api/v1/search?q=anything', [
            'X-Correlation-ID' => self::CORRELATION,
        ]);

        $this->assertProblem($response, 401, self::CORRELATION, expectExactCorrelation: true);
        $response->assertJsonPath('type', 'https://cluster.example/problems/authentication-required');
    }

    public function test_403_missing_capability_from_reporting_list_reports(): void
    {
        // ReportingApi emits 403 via its capability gate. Swap in a denying
        // decider so the controller short-circuits before touching the DB.
        $this->app->bind(DecideAccess::class, static fn (): DecideAccess => new MatrixDenyingDecider);

        $response = $this->withUnencryptedCookie('cluster_identity_session', $this->cookie)
            ->withCredentials()
            ->getJson('/api/v1/reports', ['X-Correlation-ID' => self::CORRELATION]);

        $this->assertProblem($response, 403, self::CORRELATION, expectExactCorrelation: true);
        $response->assertJsonPath('type', 'https://cluster.example/problems/access-denied');
    }

    public function test_404_unknown_export_from_reporting_download_export(): void
    {
        $response = $this->withUnencryptedCookie('cluster_identity_session', $this->cookie)
            ->withCredentials()
            ->getJson('/api/v1/exports/018f6f7d-0c00-7000-8000-000000000777', [
                'X-Correlation-ID' => self::CORRELATION,
            ]);

        $this->assertProblem($response, 404, self::CORRELATION, expectExactCorrelation: true);
        $response->assertJsonPath('type', 'https://cluster.example/problems/export-not-found');
    }

    public function test_409_idempotency_conflict_from_identity_scope_selection(): void
    {
        // Send the same Idempotency-Key with two different request bodies —
        // the first writes (so the key is recorded), the second collides.
        $headers = [
            'X-Correlation-ID' => self::CORRELATION,
            'X-CSRF-Token' => $this->csrf,
            'Idempotency-Key' => 'matrix-scope-collision',
            'If-Match' => '"1"',
        ];
        $first = $this->withUnencryptedCookie('cluster_identity_session', $this->cookie)
            ->withCredentials()
            ->putJson('/api/v1/me/scope', [
                'scope_type' => 'facility',
                'scope_id' => self::FACILITY_ID,
            ], $headers);
        $first->assertOk();
        $first->assertHeader('ETag', '"2"');

        $headers['X-Correlation-ID'] = self::SECONDARY_CORRELATION;
        $headers['If-Match'] = '"2"';
        $collision = $this->withUnencryptedCookie('cluster_identity_session', $this->cookie)
            ->withCredentials()
            ->putJson('/api/v1/me/scope', [
                'scope_type' => 'facility',
                'scope_id' => '018f6f7d-0c00-7000-8000-000000000012',
            ], $headers);

        $this->assertProblem($collision, 409, self::SECONDARY_CORRELATION, expectExactCorrelation: true);
        $collision->assertJsonPath('type', 'https://cluster.example/problems/idempotency-conflict');
    }

    public function test_412_stale_if_match_from_identity_scope_selection(): void
    {
        // The session metadata starts at scope_version=1 (see setUp). A
        // request claiming If-Match="999" cannot match, so SelectMyScopeController
        // returns 412 precondition-failed through IdentityApi::problem.
        $response = $this->withUnencryptedCookie('cluster_identity_session', $this->cookie)
            ->withCredentials()
            ->putJson('/api/v1/me/scope', [
                'scope_type' => 'facility',
                'scope_id' => self::FACILITY_ID,
            ], [
                'X-Correlation-ID' => self::CORRELATION,
                'X-CSRF-Token' => $this->csrf,
                'Idempotency-Key' => 'matrix-scope-precondition',
                'If-Match' => '"999"',
            ]);

        $this->assertProblem($response, 412, self::CORRELATION, expectExactCorrelation: true);
        $response->assertJsonPath('type', 'https://cluster.example/problems/precondition-failed');
    }

    public function test_422_weak_password_from_identity_change_password(): void
    {
        // `aaaaaaaaaaaaaa` clears the validator (length 14) but triggers
        // PasswordPolicy::violations() `repeated_characters` so the
        // controller catches WeakPassword and returns 422.
        $response = $this->withUnencryptedCookie('cluster_identity_session', $this->cookie)
            ->withCredentials()
            ->postJson(
                '/api/v1/identity/password',
                [
                    'current_password' => $this->identityPassword,
                    'new_password' => 'aaaaaaaaaaaaaa',
                    'new_password_confirmation' => 'aaaaaaaaaaaaaa',
                ],
                [
                    'X-Correlation-ID' => self::CORRELATION,
                    'X-CSRF-Token' => $this->csrf,
                ],
            );
        $this->assertProblem($response, 422, self::CORRELATION, expectExactCorrelation: true);
        $response->assertJsonPath('type', 'https://cluster.example/problems/weak-password');
    }

    /**
     * Assert the canonical `application/problem+json` envelope.
     * - Status code matches the row under test.
     * - `Content-Type` is `application/problem+json`.
     * - Body has `type`, `title`, `status` fields plus a non-empty `detail`
     *   string and a non-empty `correlation_id` field.
     * - `X-Correlation-ID` response header equals the body's `correlation_id`.
     * - When `expectExactCorrelation` is true, both equal the supplied value.
     * - No raw exception / stack-trace / debug keys leak into the body.
     */
    private function assertProblem(
        TestResponse $response,
        int $expectedStatus,
        string $expectedCorrelationId,
        bool $expectExactCorrelation,
    ): void {
        $response->assertStatus($expectedStatus);
        $response->assertHeader('Content-Type', 'application/problem+json');

        $body = $response->json();
        $this->assertIsArray($body, 'The problem response must decode to an object.');
        $this->assertArrayHasKey('type', $body);
        $this->assertArrayHasKey('title', $body);
        $this->assertArrayHasKey('status', $body);
        $this->assertSame($expectedStatus, $body['status']);
        $this->assertIsString($body['type']);
        $this->assertStringStartsWith('https://cluster.example/problems/', $body['type']);
        $this->assertIsString($body['title']);
        $this->assertNotSame('', $body['title']);

        $this->assertArrayHasKey('detail', $body);
        $this->assertIsString($body['detail']);
        $this->assertNotSame('', $body['detail']);

        $this->assertArrayHasKey('correlation_id', $body);
        $this->assertIsString($body['correlation_id']);
        $this->assertNotSame('', $body['correlation_id']);
        $this->assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $body['correlation_id'],
            'correlation_id must be a lowercase UUIDv7.',
        );

        $headerCorrelationId = $response->headers->get('X-Correlation-ID');
        $this->assertSame(
            $body['correlation_id'],
            $headerCorrelationId,
            'X-Correlation-ID header must equal body correlation_id.',
        );

        if ($expectExactCorrelation) {
            $this->assertSame($expectedCorrelationId, $body['correlation_id']);
            $this->assertSame($expectedCorrelationId, $headerCorrelationId);
        }

        foreach (['trace', 'exception', 'file', 'line', 'message'] as $forbidden) {
            $this->assertArrayNotHasKey(
                $forbidden,
                $body,
                "Problem envelope must not leak the `{$forbidden}` key.",
            );
        }

        $rawContent = (string) $response->getContent();
        $this->assertStringNotContainsString('Stack trace', $rawContent);
        $this->assertStringNotContainsString('/vendor/', $rawContent);
        $this->assertStringNotContainsString('Exception:', $rawContent);
    }
}

final class MatrixPrincipalContext implements ResolvePrincipalContext
{
    /**
     * @param  list<string>  $facilityIds
     * @param  list<string>  $clusterIds
     * @param  list<string>  $organizationUnitIds
     */
    public function __construct(
        private readonly string $userId,
        private readonly string $facilityId,
        private readonly array $facilityIds = [],
        private readonly array $clusterIds = [],
        private readonly array $organizationUnitIds = [],
    ) {}

    public function resolve(Request $request): PrincipalContext
    {
        $held = $this->facilityIds !== [] ? $this->facilityIds : [$this->facilityId];

        return new PrincipalContext(
            userId: $this->userId,
            personId: null,
            accountStatus: 'active',
            clusterIds: $this->clusterIds,
            facilityIds: $held,
            organizationUnitIds: $this->organizationUnitIds,
            primaryOrganizationUnitId: null,
            selectedScope: null,
            sessionRestricted: false,
        );
    }

    public function resolveSelectedScope(Request $request): ?array
    {
        return null;
    }

    public function persistSelectedScope(Request $request, string $scopeType, string $scopeId): void
    {
        // No-op for the matrix test — only read-side resolution matters.
    }
}

final class MatrixDenyingDecider implements DecideAccess
{
    /**
     * Test doubles persist nothing, so the read-side evaluation IS decide().
     */
    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return new AccessDecision(
            decision: 'deny',
            action: $capability,
            resourceType: $facts->resourceType ?? 'report_definition',
            reasonCodes: ['matrix_test_forced_deny'],
            policyVersion: 'matrix-test-v1',
            factsVersion: $facts->factsVersion ?? 'test',
            classification: $facts->classification ?? 'internal',
        );
    }
}
