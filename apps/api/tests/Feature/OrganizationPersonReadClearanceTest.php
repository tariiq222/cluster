<?php

namespace Tests\Feature;

use App\Http\Authentication\SessionPrincipalResolver;
use Database\Seeders\AuthorizationCatalogSeeder;
use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Tests\TestCase;

/**
 * Regression tests for the confidential-clearance gap fixed in
 * AuthorizationCatalogSeeder: read capabilities whose resources are
 * classified 'confidential' must be seeded with sensitivity 'sensitive',
 * otherwise every grant conveys only INTERNAL clearance and the RBAC+ABAC
 * engine permanently denies the read with 'classification_insufficient'.
 *
 * Decision persistence semantics (DatabasePersistAccessDecision), verified
 * while investigating this gap:
 * - Every decision (allow or deny) with a non-null decisionId is inserted
 *   into access_decisions inside one transaction.
 * - persist() returns false only when the actor user_id is missing, the
 *   decisionId is null, or the transaction throws.
 * - sensitive_access_events is additionally written for allowed decisions
 *   on classified records that carry a concrete recordId.
 */
final class OrganizationPersonReadClearanceTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000b13';

    private const SESSION_COOKIE = 'cluster_identity_session';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindRealAccessDecision();
        $this->app->bind(ResolveDevelopmentFixturePrincipal::class, SessionPrincipalResolver::class);
        $this->seed(AuthorizationCatalogSeeder::class);
        $this->seed(DevelopmentJourneyAuthorizationSeeder::class);
        config()->set('identity.session_only', true);
        DB::table('authorization_bootstrap')->update([
            'state' => 'complete',
            'completed_by_user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID,
            'completed_at' => now(),
            'lock_version' => 2,
            'updated_at' => now(),
        ]);
    }

    public function test_journey_operator_can_list_people_with_confidential_clearance(): void
    {
        [$cookie] = $this->loginSession(
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_USERNAME,
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_PASSWORD,
        );

        $response = $this->withUnencryptedCookie(self::SESSION_COOKIE, $cookie)
            ->withCredentials()
            ->getJson('/api/v1/organization/people', ['X-Correlation-ID' => self::CORRELATION_ID]);

        $response->assertOk();
        $response->assertJsonStructure(['items', 'next_cursor']);

        $this->assertDatabaseHas('access_decisions', [
            'action' => 'organization.person.read',
            'decision' => 'allow',
        ]);
    }

    public function test_confidential_reading_capabilities_are_marked_sensitive(): void
    {
        $capabilities = $this->confidentialReadingCapabilities();
        $this->assertNotEmpty($capabilities, 'The conformance scan must find at least one confidential-reading capability.');

        foreach ($capabilities as $capability) {
            $sensitivity = DB::table('capabilities')->where('capability_code', $capability)->value('sensitivity');
            $this->assertSame(
                'sensitive',
                $sensitivity,
                "Capability {$capability} is used with classification 'confidential' in a controller but is seeded as '{$sensitivity}'.",
            );
        }
    }

    /**
     * Scans every HTTP controller for decide() calls whose RecordFacts declare
     * classification 'confidential' and returns the distinct capability codes.
     *
     * @return list<string>
     */
    private function confidentialReadingCapabilities(): array
    {
        $capabilities = [];
        $directory = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('Modules')));
        foreach ($directory as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match_all("/decide\(\\\$principal, '([^']+)', new RecordFacts\((.*?)\)\)->/s", $source, $matches, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($matches as $match) {
                if (str_contains($match[2], "classification: 'confidential'")) {
                    $capabilities[] = $match[1];
                }
            }
        }

        return array_values(array_unique($capabilities));
    }

    /** @return array{0: string, 1: string} */
    private function loginSession(string $username, string $password): array
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'person-read clearance regression']);
        $response = $this->postJson('/api/v1/identity/login', [
            'username' => $username,
            'password' => $password,
        ], ['X-Correlation-ID' => self::CORRELATION_ID]);
        $response->assertOk();
        $this->assertCount(1, $response->headers->getCookies());

        return [
            (string) $response->headers->getCookies()[0]->getValue(),
            (string) $response->json('data.csrf_token'),
        ];
    }
}
