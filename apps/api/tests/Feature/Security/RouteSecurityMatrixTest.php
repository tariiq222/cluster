<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Identity\Contracts\PrincipalContext;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Modules\Organization\Contracts\AccessDecision as OrganizationAccessDecision;
use Modules\Organization\Contracts\DecideAccess as OrganizationDecideAccess;
use Modules\Organization\Contracts\RecordFacts as OrganizationRecordFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Focused matrix covering F033/F035 mutation routes whose contract is owned
 * by `Tests\Feature\OrganizationUnitReorderTest` and
 * `Tests\Feature\Http\Controllers\Organization\OrganizationTemporaryAssignmentHttpAdapterTest`.
 *
 * F049/F067/F076 are owned by `Modules\Reporting\Tests\ReportingHttpAdapterTest`;
 * F059 (anonymous/worker correlation) and F076 (identity self-read/audit) are
 * owned by `Tests\Feature\IdentityCredentialHttpAdapterTest`. The matrix
 * intentionally does not duplicate those contracts.
 */
final class RouteSecurityMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const USER_ID = '018f6f7d-0c00-7000-8000-000000000021';

    private const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000011';

    private const CORRELATION = '018f6f7d-0c00-7000-8000-000000000050';

    /**
     * @var list<array{label: string, method: string, path: string, path_parameters: array<string, string>, body: array<string, mixed>, extra_headers: array<string, string>, requires_idempotency_key: bool, requires_if_match: bool, existing_test: string, finding: string}>
     */
    private const MATRIX = [
        [
            'label' => 'organization.unit.reorder',
            'method' => 'POST',
            'path' => '/api/v1/organization/units/reorder',
            'path_parameters' => [],
            'body' => [],
            'extra_headers' => [],
            'requires_idempotency_key' => true,
            'requires_if_match' => true,
            'existing_test' => 'Tests\\Feature\\OrganizationUnitReorderTest',
            'finding' => 'F033',
        ],
        [
            'label' => 'organization.temporary_assignment.create',
            'method' => 'POST',
            'path' => '/api/v1/organization/temporary-assignments',
            'path_parameters' => [],
            'body' => [
                'person_id' => '018f6f7d-0c00-7000-8000-000000000041',
                'organization_unit_id' => '018f6f7d-0c00-7000-8000-000000000011',
                'capability_codes' => ['reporting.export'],
                'start_at' => '2026-07-26T08:00:00.000Z',
                'end_at' => '2026-07-27T08:00:00.000Z',
                'reason' => 'route-security-matrix',
            ],
            'extra_headers' => [],
            'requires_idempotency_key' => true,
            'requires_if_match' => false,
            'existing_test' => 'Tests\\Feature\\Http\\Controllers\\Organization\\OrganizationTemporaryAssignmentHttpAdapterTest',
            'finding' => 'F022, F033',
        ],
        [
            'label' => 'organization.temporary_assignment.revoke',
            'method' => 'POST',
            'path' => '/api/v1/organization/temporary-assignments/{temporaryAssignmentId}/revoke',
            'path_parameters' => ['temporaryAssignmentId' => '0197f0e0-0000-7000-8000-000000000999'],
            'body' => ['reason' => 'route-security-matrix'],
            'extra_headers' => [],
            'requires_idempotency_key' => true,
            'requires_if_match' => true,
            'existing_test' => 'Tests\\Feature\\Http\\Controllers\\Organization\\OrganizationTemporaryAssignmentHttpAdapterTest',
            'finding' => 'F035',
        ],
    ];

    private string $identityUsername = '';

    private string $identityPassword = '';

    private string $temporaryAssignmentPersonId = '';

    private string $temporaryAssignmentUnitId = '';

    private string $cookie = '';

    private string $csrf = '';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('e2e:w1-2:seed');
        $fixture = json_decode(trim(Artisan::output()), true, 8, JSON_THROW_ON_ERROR);
        $this->identityUsername = (string) $fixture['identity_username'];
        $this->identityPassword = (string) $fixture['identity_password'];
        $this->temporaryAssignmentPersonId = (string) $fixture['temporary_assignment_person_id'];
        $this->temporaryAssignmentUnitId = (string) $fixture['temporary_assignment_unit_id'];

        $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'route-security-matrix-test',
        ]);

        $login = $this->postJson('/api/v1/identity/login', [
            'username' => $this->identityUsername,
            'password' => $this->identityPassword,
        ], ['X-Correlation-ID' => self::CORRELATION])->assertOk();
        $this->cookie = (string) $login->headers->getCookies()[0]->getValue();
        $this->csrf = (string) $login->json('data.csrf_token');

        $this->app->bind(ResolvePrincipalContext::class, static fn (): ResolvePrincipalContext => new MatrixPrincipalContext(
            self::USER_ID,
            [self::FACILITY_ID],
        ));
    }

    public function test_matrix_enumerates_f033_and_f035_mutation_routes(): void
    {
        self::assertGreaterThanOrEqual(3, count(self::MATRIX));
        foreach (self::MATRIX as $row) {
            self::assertNotSame('', $row['label']);
            self::assertNotSame('', $row['path']);
            self::assertNotSame('', $row['existing_test']);
            self::assertContains($row['method'], ['POST', 'PUT', 'PATCH', 'DELETE']);
        }
    }

    #[DataProvider('matrixRows')]
    public function test_401_without_session(string $label): void
    {
        $row = $this->rowFor($label);
        $response = $this->callMethodWithoutCookie($row['method'], $row['path'], $row['path_parameters'], $row['body'], $row['extra_headers']);
        $response->assertStatus(401);
        $response->assertHeader('Content-Type', 'application/problem+json');
    }

    #[DataProvider('matrixRows')]
    public function test_403_without_capability(string $label): void
    {
        $row = $this->rowFor($label);
        // The revoke endpoint hides the existence of denied assignments:
        // with the row present and the decider denying, the controller must
        // answer 404 (not-found) so the id space stays unenumerable, and the
        // row must remain untouched. Other rows surface the denial as 403.
        if ($row['label'] === 'organization.temporary_assignment.revoke') {
            $assignmentId = $this->seedTemporaryAssignment();
            $this->app->bind(OrganizationDecideAccess::class, fn (): OrganizationDecideAccess => new MatrixOrganizationDenyingDecider);
            $headers = $this->mutationHeaders($row, includeCsrf: true, includeIdempotency: true, includeIfMatch: true);
            $response = $this->callMethodWithCookie($row['method'], $row['path'], ['temporaryAssignmentId' => $assignmentId], $row['body'], $headers);
            $response->assertStatus(404);
            $response->assertHeader('Content-Type', 'application/problem+json');
            $this->assertSame('pending', DB::table('temporary_assignments')->where('id', $assignmentId)->value('state'));

            return;
        }
        $this->app->bind(OrganizationDecideAccess::class, fn (): OrganizationDecideAccess => new MatrixOrganizationDenyingDecider);
        $headers = $this->mutationHeaders($row, includeCsrf: true, includeIdempotency: true, includeIfMatch: true);
        $response = $this->callMethodWithCookie($row['method'], $row['path'], $row['path_parameters'], $row['body'], $headers);
        $response->assertStatus(403);
        $response->assertHeader('Content-Type', 'application/problem+json');
    }

    #[DataProvider('matrixRows')]
    public function test_403_csrf_failed_without_token(string $label): void
    {
        $row = $this->rowFor($label);
        $headers = $this->mutationHeaders($row, includeCsrf: false, includeIdempotency: true, includeIfMatch: true);
        $response = $this->callMethodWithCookie($row['method'], $row['path'], $row['path_parameters'], $row['body'], $headers);
        $response->assertStatus(403);
        $response->assertHeader('Content-Type', 'application/problem+json');
    }

    #[DataProvider('matrixRows')]
    public function test_400_missing_idempotency_key_when_required(string $label): void
    {
        $row = $this->rowFor($label);
        if ($row['requires_idempotency_key'] !== true) {
            self::markTestSkipped(sprintf('Row %s does not require Idempotency-Key (finding %s).', $row['label'], $row['finding']));
        }
        $this->app->bind(OrganizationDecideAccess::class, fn (): OrganizationDecideAccess => new MatrixOrganizationAllowingDecider);
        $headers = $this->mutationHeaders($row, includeCsrf: true, includeIdempotency: false, includeIfMatch: true);
        $response = $this->callMethodWithCookie($row['method'], $row['path'], $row['path_parameters'], $row['body'], $headers);
        $response->assertStatus(400);
        $response->assertHeader('Content-Type', 'application/problem+json');
    }

    #[DataProvider('matrixRows')]
    public function test_412_stale_if_match(string $label): void
    {
        $row = $this->rowFor($label);
        if ($row['requires_if_match'] !== true) {
            self::markTestSkipped(sprintf('Row %s does not require If-Match (finding %s).', $row['label'], $row['finding']));
        }
        $this->app->bind(OrganizationDecideAccess::class, fn (): OrganizationDecideAccess => new MatrixOrganizationAllowingDecider);
        $headers = $this->mutationHeaders($row, includeCsrf: true, includeIdempotency: true, includeIfMatch: true);
        // The seeded row carries lock_version 1 while the matrix sends
        // If-Match "9999", so the CAS must answer 412 and leave the row
        // unchanged. The unit reorder row needs no seeding: its cluster
        // version can never match the matrix If-Match either.
        $pathParameters = $row['path_parameters'];
        if ($row['label'] === 'organization.temporary_assignment.revoke') {
            $pathParameters['temporaryAssignmentId'] = $this->seedTemporaryAssignment();
        }
        $response = $this->callMethodWithCookie($row['method'], $row['path'], $pathParameters, $row['body'], $headers);
        $response->assertStatus(412);
        $response->assertHeader('Content-Type', 'application/problem+json');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function matrixRows(): iterable
    {
        foreach (self::MATRIX as $row) {
            yield $row['label'] => [$row['label']];
        }
    }

    /**
     * @return array{
     *     label: string, method: string, path: string,
     *     path_parameters: array<string, string>, body: array<string, mixed>,
     *     extra_headers: array<string, string>,
     *     requires_idempotency_key: bool, requires_if_match: bool,
     *     existing_test: string, finding: string
     * }
     */
    private function rowFor(string $label): array
    {
        foreach (self::MATRIX as $row) {
            if ($row['label'] === $label) {
                return $row;
            }
        }
        self::fail(sprintf('Unknown matrix row label %s.', $label));
    }

    /**
     * @return array<string, string>
     */
    private function mutationHeaders(array $row, bool $includeCsrf, bool $includeIdempotency, bool $includeIfMatch): array
    {
        $headers = [
            'X-Correlation-ID' => self::CORRELATION,
            'Accept' => 'application/json',
        ];
        foreach ($row['extra_headers'] as $name => $value) {
            $headers[$name] = $value;
        }
        if ($includeCsrf) {
            $headers['X-CSRF-Token'] = $this->csrf;
        }
        if ($includeIdempotency && $row['requires_idempotency_key']) {
            $headers['Idempotency-Key'] = 'matrix-'.bin2hex(random_bytes(8));
        }
        if ($includeIfMatch && $row['requires_if_match']) {
            $headers['If-Match'] = '"9999"';
        }

        return $headers;
    }

    /**
     * @param  array<string, string>  $pathParameters
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $extraHeaders
     */
    private function callMethodWithoutCookie(string $method, string $path, array $pathParameters, array $body, array $extraHeaders): TestResponse
    {
        $resolved = $this->resolvePath($path, $pathParameters);
        $headers = ['X-Correlation-ID' => self::CORRELATION, ...$extraHeaders];

        return match (strtoupper($method)) {
            'POST' => $this->postJson($resolved, $body, $headers),
            'PUT' => $this->putJson($resolved, $body, $headers),
            'PATCH' => $this->patchJson($resolved, $body, $headers),
            'DELETE' => $this->deleteJson($resolved, $body, $headers),
            default => self::fail(sprintf('Unsupported HTTP method %s in matrix row.', $method)),
        };
    }

    /**
     * @param  array<string, string>  $pathParameters
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function callMethodWithCookie(string $method, string $path, array $pathParameters, array $body, array $headers): TestResponse
    {
        $resolved = $this->resolvePath($path, $pathParameters);
        $request = match (strtoupper($method)) {
            'POST' => $this->withUnencryptedCookie('cluster_identity_session', $this->cookie)->withCredentials()->postJson($resolved, $body, $headers),
            'PUT' => $this->withUnencryptedCookie('cluster_identity_session', $this->cookie)->withCredentials()->putJson($resolved, $body, $headers),
            'PATCH' => $this->withUnencryptedCookie('cluster_identity_session', $this->cookie)->withCredentials()->patchJson($resolved, $body, $headers),
            'DELETE' => $this->withUnencryptedCookie('cluster_identity_session', $this->cookie)->withCredentials()->deleteJson($resolved, $body, $headers),
            default => self::fail(sprintf('Unsupported HTTP method %s in matrix row.', $method)),
        };

        return $request;
    }

    /**
     * @param  array<string, string>  $pathParameters
     */
    private function resolvePath(string $path, array $pathParameters): string
    {
        $resolved = $path;
        foreach ($pathParameters as $name => $value) {
            $resolved = str_replace('{'.$name.'}', $value, $resolved);
        }

        return $resolved;
    }

    private function seedTemporaryAssignment(): string
    {
        $id = '0197f0e0-0000-7000-8000-000000000999';
        DB::table('temporary_assignments')->insertOrIgnore([
            'id' => $id,
            'person_id' => $this->temporaryAssignmentPersonId,
            'organization_unit_id' => $this->temporaryAssignmentUnitId,
            'start_at' => now()->subDay()->toDateTimeString(),
            'end_at' => now()->addDay()->toDateTimeString(),
            'state' => 'pending',
            'reason' => 'route-security-matrix',
            'approved_by_user_id' => self::USER_ID,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}

final class MatrixPrincipalContext implements ResolvePrincipalContext
{
    /** @param list<string> $facilityIds */
    public function __construct(private readonly string $userId, private readonly array $facilityIds) {}

    public function resolve(Request $request): PrincipalContext
    {
        return new PrincipalContext(
            userId: $this->userId,
            personId: null,
            accountStatus: 'active',
            clusterIds: [],
            facilityIds: $this->facilityIds,
            organizationUnitIds: [],
            primaryOrganizationUnitId: null,
            selectedScope: null,
            sessionRestricted: false,
        );
    }

    public function resolveSelectedScope(Request $request): ?array
    {
        return null;
    }

    public function persistSelectedScope(Request $request, string $scopeType, string $scopeId): void {}
}

final class MatrixOrganizationDenyingDecider implements OrganizationDecideAccess
{
    public function decide(array $actor, string $capability, ?OrganizationRecordFacts $facts): OrganizationAccessDecision
    {
        [$resourceType, $factsVersion, $classification] = $facts === null
            ? ['route_security_matrix', 'test', 'internal']
            : [$facts->resourceType, $facts->factsVersion, $facts->classification];

        return new OrganizationAccessDecision('deny', $capability, $resourceType, ['matrix_test'], 'matrix-v1', $factsVersion, $classification);
    }
}

final class MatrixOrganizationAllowingDecider implements OrganizationDecideAccess
{
    public function decide(array $actor, string $capability, ?OrganizationRecordFacts $facts): OrganizationAccessDecision
    {
        [$resourceType, $factsVersion, $classification] = $facts === null
            ? ['route_security_matrix', 'test', 'internal']
            : [$facts->resourceType, $facts->factsVersion, $facts->classification];

        return new OrganizationAccessDecision('allow', $capability, $resourceType, ['matrix_test'], 'matrix-v1', $factsVersion, $classification);
    }
}
