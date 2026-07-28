<?php

namespace Tests\Feature;

use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Tests\TestCase;

/**
 * Pins the work-management feature gate (spec §4):
 * disabled mutations → 409 urn:cluster:problem:feature-disabled with zero
 * side effects; disabled reads → non-disclosing 404 unless the principal
 * holds work_management.history.read; task routes stay open.
 */
final class WorkManagementFeatureGateTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION = '018f6f7d-0c00-7000-8000-000000000701';

    private string $token;

    /** The active access double; reads/writes its $calls property. */
    private object $accessDouble;

    protected function setUp(): void
    {
        parent::setUp();
        // Parent TestCase defaults the feature on for legacy workflow tests;
        // override here to exercise the gate (off by default in production).
        config()->set('features.work_management', false);
        $this->seed(DevelopmentJourneyAuthorizationSeeder::class);
        $this->token = $this->loginToken('fixture-account-a', 'fixture-password-a', self::CORRELATION);
    }

    private function loginToken(string $username, string $password, string $correlationId): string
    {
        $response = $this->postJson(
            '/api/v1/auth/login',
            ['username' => $username, 'password' => $password],
            ['X-Correlation-ID' => $correlationId]
        )->assertOk();

        return (string) $response->json('data.access_token');
    }

    private function bindAccess(bool $allowed): void
    {
        $this->accessDouble = new class($allowed) implements DecideAccess
        {
            /** @var list<string> */
            public array $calls = [];

            public function __construct(private readonly bool $allowed) {}

            public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                $this->calls[] = 'decide';

                return $this->verdict($capability);
            }

            public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                $this->calls[] = 'evaluateOnly';

                return $this->verdict($capability);
            }

            private function verdict(string $capability): AccessDecision
            {
                return new AccessDecision(
                    decision: $this->allowed ? 'allow' : 'deny',
                    action: $capability,
                    resourceType: 'work_management',
                    reasonCodes: [],
                    policyVersion: 'test',
                    factsVersion: 'test',
                    classification: 'internal',
                );
            }
        };
        $this->app->instance(DecideAccess::class, $this->accessDouble);
    }

    public function test_work_record_mutation_returns_409_feature_disabled_with_zero_side_effects(): void
    {
        $baselineWorkRecords = DB::table('work_records')->count();
        $baselineOutbox = DB::table('outbox_events')->count();
        $baselineNotifications = DB::table('notifications')->count();

        $response = $this->withToken($this->token)->postJson('/api/v1/work-records', [
            'work_definition_code' => 'request',
            'title' => 'Blocked request',
            'description' => 'must not persist',
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-gated-'.Str::uuid7()->toString(),
        ]);

        $response->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'urn:cluster:problem:feature-disabled');

        // Zero side effects: no persistence, no outbox, no notifications.
        $this->assertSame($baselineWorkRecords, DB::table('work_records')->count());
        $this->assertSame($baselineOutbox, DB::table('outbox_events')->count());
        $this->assertSame($baselineNotifications, DB::table('notifications')->count());
    }

    public function test_workflow_mutation_returns_409_feature_disabled(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/v1/workflow/instances', [], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-gated-'.Str::uuid7()->toString(),
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('type', 'urn:cluster:problem:feature-disabled');
    }

    public function test_work_definition_mutation_returns_409_feature_disabled(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/v1/work-definitions', [], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-gated-'.Str::uuid7()->toString(),
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('type', 'urn:cluster:problem:feature-disabled');
    }

    public function test_read_without_history_capability_returns_non_disclosing_404(): void
    {
        $this->bindAccess(false);
        $baselineDecisions = DB::table('access_decisions')->count();

        $list = $this->withToken($this->token)
            ->getJson('/api/v1/workflow/instances', ['X-Correlation-ID' => self::CORRELATION]);
        $detail = $this->withToken($this->token)
            ->getJson('/api/v1/work-records/'.Str::uuid7()->toString(), ['X-Correlation-ID' => self::CORRELATION]);

        $list->assertStatus(404)->assertJsonPath('type', 'https://cluster.example/problems/resource-unavailable');
        $detail->assertStatus(404)->assertJsonPath('type', 'https://cluster.example/problems/resource-unavailable');
        // Non-disclosure: the gate emits one fixed body regardless of route or id.
        $this->assertSame($list->json(), $detail->json());
        // Read-side gate must not persist access decisions.
        $this->assertContains('evaluateOnly', $this->accessDouble->calls);
        $this->assertNotContains('decide', $this->accessDouble->calls);
        $this->assertSame($baselineDecisions, DB::table('access_decisions')->count());
    }

    public function test_read_with_history_capability_passes_through(): void
    {
        $this->bindAccess(true);

        $response = $this->withToken($this->token)
            ->getJson('/api/v1/workflow/instances', ['X-Correlation-ID' => self::CORRELATION]);

        $response->assertOk();
        $this->assertContains('evaluateOnly', $this->accessDouble->calls);
    }

    public function test_task_routes_are_not_gated(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/v1/tasks', ['X-Correlation-ID' => self::CORRELATION]);

        $response->assertOk();
    }

    public function test_gate_passes_through_when_feature_enabled(): void
    {
        config()->set('features.work_management', true);
        $this->bindAccess(true);

        $read = $this->withToken($this->token)
            ->getJson('/api/v1/workflow/instances', ['X-Correlation-ID' => self::CORRELATION]);
        $read->assertOk();

        $mutation = $this->withToken($this->token)->postJson('/api/v1/workflow/instances', [], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-enabled-'.Str::uuid7()->toString(),
        ]);
        // Whatever the controller decides, the gate must not answer.
        $this->assertNotSame('urn:cluster:problem:feature-disabled', $mutation->json('type'));
    }
}
