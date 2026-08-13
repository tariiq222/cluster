<?php

namespace Tests\Feature;

use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Audit\Contracts\AuditEventInput;
use Modules\Audit\Contracts\AuditEventReceipt;
use Modules\Audit\Contracts\RecordAuditEvent;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Features\Bootstrap\Http\CompleteAuthorizationBootstrapController;
use Modules\Authorization\Features\Bootstrap\Http\GetAuthorizationBootstrapController;
use Modules\Authorization\Features\OperationsOffice\BootstrapOperationsOffice;
use Modules\Authorization\Infrastructure\BootstrapGatedDecideAccess;
use Modules\Authorization\Infrastructure\Persistence\AuthorizationBootstrapState;
use Modules\Authorization\Infrastructure\RbacAbacDecideAccess;
use Modules\Identity\Contracts\ResolveAccountEntitlement;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Contracts\GetActiveSupervisoryRelationships;
use RuntimeException;
use Tests\TestCase;

final class AuthorizationBootstrapStage00CTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_bootstrap_denies_business_capabilities_by_default(): void
    {
        $gate = $this->gate();

        $decision = $gate->decide(['user_id' => Str::uuid7()->toString()], 'tasks.read', $this->facts());

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['authorization_bootstrap_pending'], $decision->reasonCodes);
    }

    public function test_canonical_setup_capabilities_bypass_only_the_bootstrap_gate(): void
    {
        $gate = $this->gate();

        foreach (BootstrapGatedDecideAccess::SETUP_CAPABILITIES as $capability) {
            $decision = $gate->decide(['user_id' => Str::uuid7()->toString()], $capability, $this->facts());
            $this->assertNotContains('authorization_bootstrap_pending', $decision->reasonCodes);
        }
    }

    public function test_pending_bootstrap_does_not_block_a_platform_owner(): void
    {
        $ownerId = Str::uuid7()->toString();
        $this->app->make(BootstrapOperationsOffice::class)->bootstrap($ownerId, Str::uuid7()->toString());

        $decision = $this->gate()->decide(['user_id' => $ownerId], 'tasks.read', $this->facts());

        $this->assertTrue($decision->isAllowed());
        $this->assertContains('platform_owner_super_admin_override', $decision->reasonCodes);
    }

    public function test_completion_records_exactly_one_audit_event_and_none_on_replay_or_conflict(): void
    {
        $recorder = $this->bindCapturingRecorder();
        $principalId = Str::uuid7()->toString();
        $state = $this->app->make(AuthorizationBootstrapState::class);
        $hash = hash('sha256', json_encode(['reason' => 'Initial setup complete'], JSON_THROW_ON_ERROR));

        $completed = $state->complete($principalId, 'Initial setup complete', 'bootstrap-key', $hash);
        $replay = $state->complete($principalId, 'Initial setup complete', 'bootstrap-key', $hash);
        $secondKey = $state->complete($principalId, 'Initial setup complete', 'another-key', $hash);

        $this->assertSame('completed', $completed['status']);
        $this->assertSame(2, $completed['version']);
        $this->assertSame('replay', $replay['status']);
        $this->assertSame($completed['payload'], $replay['payload']);
        $this->assertSame('conflict', $secondKey['status']);
        $this->assertDatabaseCount('authorization_idempotency_keys', 1);
        $this->assertDatabaseHas('access_decisions', [
            'actor_user_id' => $principalId,
            'action' => 'authorization.bootstrap.complete',
            'resource_type' => 'authorization_bootstrap',
        ]);
        $this->assertCount(1, $recorder->calls, 'Audit recorder must be invoked exactly once on first completion.');
        $this->assertSame('complete', $completed['payload']['state']);
    }

    public function test_completion_audit_event_input_matches_the_allow_listed_contract(): void
    {
        $recorder = $this->bindCapturingRecorder();
        $principalId = Str::uuid7()->toString();
        $state = $this->app->make(AuthorizationBootstrapState::class);
        $hash = hash('sha256', json_encode(['reason' => 'Initial setup complete'], JSON_THROW_ON_ERROR));

        $state->complete($principalId, 'Initial setup complete', 'bootstrap-key', $hash);

        $this->assertCount(1, $recorder->calls);
        $input = $recorder->calls[0];

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $input->eventId);
        $this->assertSame('authorization', $input->sourceModule);
        $this->assertSame('authorization.bootstrap.completed', $input->action);
        $this->assertSame('com.cluster.authorization.bootstrapcompleted.v1', $input->eventType);
        $this->assertSame(AuditEventInput::ACTOR_USER, $input->actorType);
        $this->assertSame($principalId, $input->actorId);
        $this->assertNull($input->originalActorId);
        $this->assertSame('authorization_bootstrap', $input->subjectType);
        $this->assertNotNull($input->subjectId);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', (string) $input->subjectId);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $input->correlationId);
        $this->assertSame(AuditEventInput::OUTCOME_SUCCEEDED, $input->outcome);
        $this->assertSame(AuditEventInput::CLASSIFICATION_INTERNAL, $input->classification);
        $this->assertSame(AuditEventInput::RETENTION_SECURITY, $input->retentionClass);

        $accessCorrelation = (string) DB::table('access_decisions')->value('correlation_id');
        $this->assertSame($input->correlationId, $accessCorrelation, 'access_decisions.correlation_id must reuse the Audit correlation UUID.');

        $context = AuditEventInput::canonicalizeContext($input->context);
        $contextKeys = array_keys($context);
        sort($contextKeys);
        $this->assertSame(['state', 'version'], $contextKeys, 'Audit context must be limited to the allow-list {state, version}.');
        $this->assertSame('complete', $context['state']);
        $this->assertSame(2, $context['version']);

        $occurredAt = $input->occurredAt;
        $this->assertInstanceOf(DateTimeImmutable::class, $occurredAt);
        $this->assertSame('UTC', $occurredAt->getTimezone()->getName());
    }

    public function test_changed_payload_replay_conflicts_without_mutating_completed_state(): void
    {
        $recorder = $this->bindCapturingRecorder();
        $principalId = Str::uuid7()->toString();
        $state = $this->app->make(AuthorizationBootstrapState::class);
        $state->complete($principalId, 'Initial setup complete', 'bootstrap-key', hash('sha256', 'first'));

        $conflict = $state->complete($principalId, 'Different reason', 'bootstrap-key', hash('sha256', 'second'));

        $this->assertSame('conflict', $conflict['status']);
        $this->assertSame('complete', DB::table('authorization_bootstrap')->value('state'));
        $this->assertSame(2, DB::table('authorization_bootstrap')->value('lock_version'));
        $this->assertDatabaseCount('authorization_idempotency_keys', 1);
        $this->assertDatabaseCount('access_decisions', 1);
        $this->assertCount(1, $recorder->calls, 'Mismatch replay must NOT call Audit a second time.');
    }

    public function test_completion_rollback_is_total_when_record_audit_event_throws(): void
    {
        $recorder = $this->bindThrowingRecorder(new RuntimeException('audit_failure_simulated'));
        $principalId = Str::uuid7()->toString();
        $state = $this->app->make(AuthorizationBootstrapState::class);
        $hash = hash('sha256', json_encode(['reason' => 'Initial setup complete'], JSON_THROW_ON_ERROR));

        try {
            $state->complete($principalId, 'Initial setup complete', 'bootstrap-key', $hash);
            $this->fail('Expected the throwing recorder to bubble the failure out of complete().');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit_failure_simulated', $exception->getMessage());
        }

        $this->assertSame('pending', DB::table('authorization_bootstrap')->value('state'));
        $this->assertSame(1, (int) DB::table('authorization_bootstrap')->value('lock_version'));
        $this->assertNull(DB::table('authorization_bootstrap')->value('completed_at'));
        $this->assertNull(DB::table('authorization_bootstrap')->value('completed_by_user_id'));
        $this->assertSame(0, DB::table('authorization_idempotency_keys')->count());
        $this->assertSame(0, DB::table('access_decisions')->count());
        $this->assertGreaterThanOrEqual(1, count($recorder->calls), 'Recorder should have been attempted at least once.');
    }

    public function test_bootstrap_http_responses_expose_stable_version_etags(): void
    {
        $this->bindCapturingRecorder();
        $principalId = Str::uuid7()->toString();
        $principalResolver = new class($principalId) implements ResolveDevelopmentFixturePrincipal
        {
            public function __construct(private readonly string $principalId) {}

            public function issue(array $principal): array
            {
                return ['access_token' => 'unused', 'expires_at' => now()->addMinute()->toIso8601String()];
            }

            public function resolve(Request $request): array
            {
                return ['user_id' => $this->principalId, 'facility_id' => '018f6f7d-0c00-7000-8000-000000000011'];
            }
        };
        $entitlements = new class implements ResolveAccountEntitlement
        {
            public function resolve(string $userId): array
            {
                return ['active' => true, 'administrator' => true];
            }
        };
        $state = $this->app->make(AuthorizationBootstrapState::class);
        $get = new GetAuthorizationBootstrapController($principalResolver, $state);
        $complete = new CompleteAuthorizationBootstrapController(
            $principalResolver,
            $entitlements,
            $state,
            $this->app->make(BootstrapOperationsOffice::class),
            $this->app->make(\Modules\Organization\Contracts\GetDefaultClusterId::class),
        );
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

    private function bindCapturingRecorder(): CapturingAuditRecorder
    {
        $recorder = new CapturingAuditRecorder;
        $this->app->instance(RecordAuditEvent::class, $recorder);
        $this->app->forgetInstance(AuthorizationBootstrapState::class);

        return $recorder;
    }

    private function bindThrowingRecorder(\Throwable $failure): CapturingAuditRecorder
    {
        $recorder = new CapturingAuditRecorder($failure);
        $this->app->instance(RecordAuditEvent::class, $recorder);
        $this->app->forgetInstance(AuthorizationBootstrapState::class);

        return $recorder;
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
            ownerFacilityId: Str::uuid7()->toString(),
            resourceType: 'task',
            classification: 'internal',
            recordId: Str::uuid7()->toString(),
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

final class CapturingAuditRecorder implements RecordAuditEvent
{
    /** @var list<AuditEventInput> */
    public array $calls = [];

    private ?\Throwable $failure;

    public function __construct(?\Throwable $failure = null)
    {
        $this->failure = $failure;
    }

    public function record(AuditEventInput $input): AuditEventReceipt
    {
        $this->calls[] = $input;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new AuditEventReceipt(
            eventId: $input->eventId,
            streamKey: 'authorization:authorization_bootstrap:'.$input->subjectId,
            streamSequence: count($this->calls),
            eventHash: str_repeat('0', 64),
            recordedAt: $input->occurredAt,
            replayed: false,
        );
    }
}
