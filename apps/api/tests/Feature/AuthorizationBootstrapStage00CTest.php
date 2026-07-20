<?php

namespace Tests\Feature;

use App\Http\Controllers\Authorization\CompleteAuthorizationBootstrapController;
use App\Http\Controllers\Authorization\GetAuthorizationBootstrapController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Infrastructure\BootstrapGatedDecideAccess;
use Modules\Authorization\Infrastructure\Persistence\AuthorizationBootstrapState;
use Modules\Authorization\Infrastructure\RbacAbacDecideAccess;
use Modules\Identity\Contracts\ResolveAccountEntitlement;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Contracts\GetActiveSupervisoryRelationships;
use Tests\TestCase;

final class AuthorizationBootstrapStage00CTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_bootstrap_denies_business_capabilities_by_default(): void
    {
        $gate = $this->gate();

        $decision = $gate->decide(['user_id' => fake()->uuid()], 'work_record.read', $this->facts());

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['authorization_bootstrap_pending'], $decision->reasonCodes);
    }

    public function test_canonical_setup_capabilities_bypass_only_the_bootstrap_gate(): void
    {
        $gate = $this->gate();

        foreach (BootstrapGatedDecideAccess::SETUP_CAPABILITIES as $capability) {
            $decision = $gate->decide(['user_id' => fake()->uuid()], $capability, $this->facts());
            $this->assertNotContains('authorization_bootstrap_pending', $decision->reasonCodes);
        }
    }

    public function test_completion_is_atomic_idempotent_audited_and_one_way(): void
    {
        $principalId = fake()->uuid();
        $state = $this->app->make(AuthorizationBootstrapState::class);
        $hash = hash('sha256', json_encode(['reason' => 'Initial setup complete'], JSON_THROW_ON_ERROR));

        $completed = $state->complete($principalId, 'Initial setup complete', 'bootstrap-key', $hash);
        $replay = $state->complete($principalId, 'Initial setup complete', 'bootstrap-key', $hash);
        $secondKey = $state->complete($principalId, 'Initial setup complete', 'another-key', $hash);

        $this->assertSame('completed', $completed['status']);
        $this->assertSame(2, $completed['payload']['version']);
        $this->assertSame('replay', $replay['status']);
        $this->assertSame($completed['payload'], $replay['payload']);
        $this->assertSame('conflict', $secondKey['status']);
        $this->assertDatabaseCount('authorization_idempotency_keys', 1);
        $this->assertDatabaseHas('access_decisions', [
            'actor_user_id' => $principalId,
            'action' => 'authorization.bootstrap.complete',
            'resource_type' => 'authorization_bootstrap',
        ]);
    }

    public function test_changed_payload_replay_conflicts_without_mutating_completed_state(): void
    {
        $principalId = fake()->uuid();
        $state = $this->app->make(AuthorizationBootstrapState::class);
        $state->complete($principalId, 'Initial setup complete', 'bootstrap-key', hash('sha256', 'first'));

        $conflict = $state->complete($principalId, 'Different reason', 'bootstrap-key', hash('sha256', 'second'));

        $this->assertSame('conflict', $conflict['status']);
        $this->assertSame('complete', DB::table('authorization_bootstrap')->value('state'));
        $this->assertSame(2, DB::table('authorization_bootstrap')->value('lock_version'));
        $this->assertDatabaseCount('authorization_idempotency_keys', 1);
        $this->assertDatabaseCount('access_decisions', 1);
    }

    public function test_bootstrap_http_responses_expose_stable_version_etags(): void
    {
        $principalId = fake()->uuid();
        $principalResolver = new class($principalId) implements ResolveDevelopmentFixturePrincipal
        {
            public function __construct(private readonly string $principalId) {}

            public function issue(array $principal): array
            {
                return ['access_token' => 'unused', 'expires_at' => now()->addMinute()->toIso8601String()];
            }

            public function resolve(Request $request): ?array
            {
                return ['user_id' => $this->principalId, 'facility_id' => null];
            }
        };
        $entitlements = new class implements ResolveAccountEntitlement
        {
            public function resolve(string $userId): ?array
            {
                return ['active' => true, 'administrator' => true];
            }
        };
        $state = $this->app->make(AuthorizationBootstrapState::class);
        $get = new GetAuthorizationBootstrapController($principalResolver, $state);
        $complete = new CompleteAuthorizationBootstrapController($principalResolver, $entitlements, $state);
        $headers = ['X-Correlation-ID' => '0190f5d2-7b9a-7000-8000-000000000001'];

        $first = $get(Request::create('/api/v1/authorization/bootstrap', 'GET', server: $this->server($headers)));
        $second = $get(Request::create('/api/v1/authorization/bootstrap', 'GET', server: $this->server($headers)));
        $completionRequest = Request::create(
            '/api/v1/authorization/bootstrap/complete',
            'POST',
            server: $this->server($headers + ['Idempotency-Key' => 'bootstrap-http-key']),
            content: json_encode(['reason' => 'Initial setup complete'], JSON_THROW_ON_ERROR),
        );
        $completionRequest->headers->set('Content-Type', 'application/json');
        $completed = $complete($completionRequest);
        $after = $get(Request::create('/api/v1/authorization/bootstrap', 'GET', server: $this->server($headers)));

        $this->assertSame('"1"', $first->headers->get('ETag'));
        $this->assertSame($first->headers->get('ETag'), $second->headers->get('ETag'));
        $this->assertSame('"2"', $completed->headers->get('ETag'));
        $this->assertSame($completed->headers->get('ETag'), $after->headers->get('ETag'));
    }

    private function gate(): BootstrapGatedDecideAccess
    {
        return new BootstrapGatedDecideAccess(
            new RbacAbacDecideAccess($this->app->make(GetActiveSupervisoryRelationships::class)),
            $this->app->make(AuthorizationBootstrapState::class),
        );
    }

    private function facts(): RecordFacts
    {
        return new RecordFacts(
            ownerFacilityId: fake()->uuid(),
            resourceType: 'work_record',
            classification: 'internal',
            recordId: fake()->uuid(),
        );
    }

    /** @param array<string, string> $headers */
    private function server(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }
}
