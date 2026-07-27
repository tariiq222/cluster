<?php

declare(strict_types=1);

namespace Modules\Audit\Tests;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Audit\Features\GetAuditEvent\Http\GetAuditEventController;
use Modules\Audit\Features\ListAuditEvents\Http\ListAuditEventsController;
use Modules\Audit\Http\AuditApi;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\PrincipalContext;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Tests\TestCase;

final class AuditAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public const USER_ID = '018f6f7d-0c00-7000-8000-000000000501';

    public const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000502';

    private const OTHER_FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000503';

    public const UNIT_ID = '018f6f7d-0c00-7000-8000-000000000504';

    private const OTHER_UNIT_ID = '018f6f7d-0c00-7000-8000-000000000505';

    private const ACTOR_ID = '018f6f7d-0c00-7000-8000-000000000506';

    private const SUBJECT_ID = '018f6f7d-0c00-7000-8000-000000000507';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000508';

    private const DECISION_ID = '018f6f7d-0c00-7000-8000-000000000509';

    private AuditAuthorizationDecisionEngine $decisions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ResolvePrincipalContext::class, new AuditAuthorizationPrincipalResolver);
        $this->decisions = new AuditAuthorizationDecisionEngine(
            self::FACILITY_ID,
            self::UNIT_ID,
            self::DECISION_ID,
        );
        $this->app->instance(DecideAccess::class, $this->decisions);

        Route::middleware(AuditAuthorizationSessionMiddleware::class)
            ->get(AuditApi::ROUTE_LIST, ListAuditEventsController::class)
            ->name('audit.authorization-test.events.index');
        Route::middleware(AuditAuthorizationSessionMiddleware::class)
            ->get(AuditApi::ROUTE_GET, GetAuditEventController::class)
            ->name('audit.authorization-test.events.show');
    }

    public function test_per_row_denial_is_skipped_and_over_fetch_fills_an_authorized_page_without_counts(): void
    {
        $deniedId = '018f6f7d-0c00-7000-8000-000000000531';
        $firstAllowedId = '018f6f7d-0c00-7000-8000-000000000532';
        $secondAllowedId = '018f6f7d-0c00-7000-8000-000000000533';
        $lookaheadId = '018f6f7d-0c00-7000-8000-000000000534';
        $scopeContext = [
            'facility_id' => self::FACILITY_ID,
            'organization_unit_ids' => [self::UNIT_ID],
        ];
        $this->insertEvent($deniedId, 4, '2026-07-27 12:00:00.003', $scopeContext);
        $this->insertEvent($firstAllowedId, 3, '2026-07-27 12:00:00.002', $scopeContext);
        $this->insertEvent($secondAllowedId, 2, '2026-07-27 12:00:00.001', $scopeContext);
        $this->insertEvent($lookaheadId, 1, '2026-07-27 12:00:00.000', $scopeContext);
        $this->decisions->deniedRecordIds[] = $deniedId;

        $response = $this->getJson(AuditApi::ROUTE_LIST.'?limit=2', $this->headers())
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.event_id', $firstAllowedId)
            ->assertJsonPath('items.1.event_id', $secondAllowedId)
            ->assertJsonMissingPath('total')
            ->assertJsonMissingPath('unauthorized_total');

        $this->assertNotNull($response->json('next_cursor'));
        $rowCalls = array_values(array_filter(
            $this->decisions->calls,
            static fn (array $call): bool => $call['facts']->resourceType === 'audit_event',
        ));
        $this->assertCount(4, $rowCalls);
        $this->assertSame($deniedId, $rowCalls[0]['facts']->recordId);
        $this->assertSame(self::FACILITY_ID, $rowCalls[0]['facts']->ownerFacilityId);
        $this->assertSame(self::UNIT_ID, $rowCalls[0]['facts']->organizationUnitId);
        $this->assertSame([self::UNIT_ID], $rowCalls[0]['facts']->sharedUnitIds);
        $this->assertSame('documents', $rowCalls[0]['facts']->sourceModule);
        $this->assertSame('internal', $rowCalls[0]['facts']->classification);
    }

    public function test_missing_out_of_scope_and_base_denied_detail_are_byte_equivalent_404s(): void
    {
        $outOfScopeId = '018f6f7d-0c00-7000-8000-000000000541';
        $missingId = '018f6f7d-0c00-7000-8000-000000000542';
        $this->insertEvent($outOfScopeId, 1, '2026-07-27 12:00:00.000', [
            'facility_id' => self::OTHER_FACILITY_ID,
            'organization_unit_ids' => [self::OTHER_UNIT_ID],
        ]);

        $missing = $this->getJson($this->detailUrl($missingId), $this->headers())
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json');
        $denied = $this->getJson($this->detailUrl($outOfScopeId), $this->headers())
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->assertSame($missing->getContent(), $denied->getContent());
        $this->assertSame($missing->headers->get('Content-Type'), $denied->headers->get('Content-Type'));
        $this->assertSame($missing->headers->get('X-Correlation-ID'), $denied->headers->get('X-Correlation-ID'));
        $missing->assertJsonPath('type', 'https://cluster.example/problems/audit-event-not-found')
            ->assertJsonPath('detail', 'The audit event was not found.');

        $this->decisions->allowCollection = false;
        $baseDenied = $this->getJson($this->detailUrl($outOfScopeId), $this->headers())
            ->assertNotFound();
        $this->assertSame($missing->getContent(), $baseDenied->getContent());
    }

    public function test_projection_masks_and_hides_context_after_read_time_redaction(): void
    {
        $eventId = '018f6f7d-0c00-7000-8000-000000000551';
        $this->insertEvent($eventId, 1, '2026-07-27 12:00:00.000', [
            'facility_id' => self::FACILITY_ID,
            'organization_unit_id' => self::UNIT_ID,
            'token' => 'must-not-leave-audit',
            'display_label' => 'sensitive label',
            'hidden_note' => 'sensitive note',
            'nested' => ['authorization' => 'Bearer secret-value'],
        ]);
        $this->decisions->maskedRecordIds[] = $eventId;

        $this->getJson($this->detailUrl($eventId), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.context.token', '[REDACTED]')
            ->assertJsonPath('data.context.display_label', '[REDACTED]')
            ->assertJsonMissingPath('data.context.hidden_note')
            ->assertJsonPath('data.context.nested.authorization', '[REDACTED]')
            ->assertJsonPath('data.access_decision_id', self::DECISION_ID)
            ->assertJsonPath('data.allowed_actions', ['audit.event.read'])
            ->assertJsonMissingPath('data.field_access')
            ->assertJsonMissingPath('data.context_raw');
    }

    public function test_invalid_persisted_scope_values_are_not_promoted_to_authorization_facts(): void
    {
        $eventId = '018f6f7d-0c00-7000-8000-000000000561';
        $this->insertEvent($eventId, 1, '2026-07-27 12:00:00.000', [
            'facility_id' => 'not-a-uuid',
            'organization_unit_id' => strtoupper(self::UNIT_ID),
            'organization_unit_ids' => [self::UNIT_ID, 'invalid'],
        ]);

        $this->getJson($this->detailUrl($eventId), $this->headers())->assertOk();

        $rowCall = array_values(array_filter(
            $this->decisions->calls,
            static fn (array $call): bool => $call['facts']->resourceType === 'audit_event',
        ))[0];
        $this->assertNull($rowCall['facts']->ownerFacilityId);
        $this->assertNull($rowCall['facts']->organizationUnitId);
        $this->assertSame([], $rowCall['facts']->sharedUnitIds);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Correlation-ID' => self::CORRELATION_ID];
    }

    private function detailUrl(string $eventId): string
    {
        return str_replace('{eventId}', $eventId, AuditApi::ROUTE_GET);
    }

    /** @param array<string, mixed> $context */
    private function insertEvent(string $id, int $sequence, string $recordedAt, array $context = []): void
    {
        DB::table('audit_events')->insert([
            'id' => $id,
            'request_hash' => hash('sha256', 'authorization-request-'.$id),
            'stream_key' => 'documents:document:'.self::SUBJECT_ID,
            'stream_sequence' => $sequence,
            'source_module' => 'documents',
            'action' => 'document.viewed',
            'event_type' => 'com.cluster.documents.documentviewed.v1',
            'actor_type' => 'user',
            'actor_id' => self::ACTOR_ID,
            'original_actor_id' => null,
            'subject_type' => 'document',
            'subject_id' => self::SUBJECT_ID,
            'correlation_id' => self::CORRELATION_ID,
            'outcome' => 'succeeded',
            'classification' => 'internal',
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
            'context_schema_version' => 1,
            'redaction_policy_version' => 'v1',
            'occurred_at' => '2026-07-27 11:00:00.000',
            'recorded_at' => $recordedAt,
            'retention_until' => '2033-07-27 12:00:00.000',
            'previous_hash' => $sequence === 1 ? null : hash('sha256', 'authorization-event-'.($sequence - 1)),
            'event_hash' => hash('sha256', 'authorization-event-'.$sequence),
            'integrity_key_version' => 'v1',
        ]);
    }

    /**
     * Unit-only principals (no facilities, only an organization unit) must
     * get the canonical primaryOrganizationUnitId fallback so the audit
     * scope facts match every other module's owner-facility matching. A
     * facility-bounded event whose ownerFacilityId matches the principal's
     * primaryOrganizationUnitId must still be reachable.
     */
    public function test_unit_only_principal_falls_back_to_primary_organization_unit_facility(): void
    {
        $sharedUnitId = '018f6f7d-0c00-7000-8000-000000000591';
        $primaryOrganizationUnitId = '018f6f7d-0c00-7000-8000-000000000592';
        $ownerFacilityId = $primaryOrganizationUnitId;
        $eventId = '018f6f7d-0c00-7000-8000-000000000593';
        $this->insertEvent($eventId, 1, '2026-07-27 12:00:00.000', [
            'facility_id' => $ownerFacilityId,
            'organization_unit_id' => $primaryOrganizationUnitId,
        ]);

        $principal = new PrincipalContext(
            userId: self::USER_ID,
            personId: null,
            accountStatus: 'active',
            clusterIds: [],
            facilityIds: [],
            organizationUnitIds: [$primaryOrganizationUnitId],
            primaryOrganizationUnitId: $primaryOrganizationUnitId,
            selectedScope: ['scope_type' => 'organization_unit', 'scope_id' => $primaryOrganizationUnitId],
            sessionRestricted: false,
        );

        $scope = AuditApi::scope($principal);
        $this->assertSame(
            $primaryOrganizationUnitId,
            $scope['facility_id'],
            'Unit-only principal must inherit the primaryOrganizationUnitId as the canonical facility fallback.',
        );
        $this->assertSame(
            [$primaryOrganizationUnitId],
            $scope['organization_unit_ids'],
        );

        $actor = AuditApi::actor($principal, $scope, self::CORRELATION_ID);
        $this->assertSame(
            $primaryOrganizationUnitId,
            $actor['facility_id'],
            'Actor facts must mirror the canonical fallback so other modules see the same owner-facility semantics.',
        );
        $this->assertSame(
            '018f6f7d-0c00-7000-8000-000000000501',
            $actor['user_id'],
            'Actor user_id comes from the principal; the fallback does not disclose additional principal data.',
        );
        $this->assertSame(
            self::CORRELATION_ID,
            $actor['correlation_id'],
        );
        $this->assertSame(
            [$primaryOrganizationUnitId],
            $actor['organization_unit_ids'],
        );
    }

    /**
     * The facility-from-facilityIds path MUST NOT be weakened by the new
     * fallback: a principal whose facilityIds[0] is set keeps that
     * facility even when a primaryOrganizationUnitId is also recorded.
     */
    public function test_principal_with_facility_keeps_facility_ids_zero_over_primary_unit_fallback(): void
    {
        $primaryOrganizationUnitId = '018f6f7d-0c00-7000-8000-000000000594';
        $principal = new PrincipalContext(
            userId: self::USER_ID,
            personId: null,
            accountStatus: 'active',
            clusterIds: [],
            facilityIds: [self::FACILITY_ID],
            organizationUnitIds: [$primaryOrganizationUnitId],
            primaryOrganizationUnitId: $primaryOrganizationUnitId,
            selectedScope: ['scope_type' => 'facility', 'scope_id' => self::FACILITY_ID],
            sessionRestricted: false,
        );

        $scope = AuditApi::scope($principal);
        $this->assertSame(
            self::FACILITY_ID,
            $scope['facility_id'],
            'When facilityIds[0] is set, it must win over the primaryOrganizationUnitId fallback.',
        );
    }

    /**
     * The selected facility scope must still override the fallback when
     * it is one of the principal's facilities.
     */
    public function test_selected_facility_scope_overrides_the_primary_unit_fallback(): void
    {
        $primaryOrganizationUnitId = '018f6f7d-0c00-7000-8000-000000000595';
        $otherFacilityId = '018f6f7d-0c00-7000-8000-000000000596';
        $principal = new PrincipalContext(
            userId: self::USER_ID,
            personId: null,
            accountStatus: 'active',
            clusterIds: [],
            facilityIds: [self::FACILITY_ID, $otherFacilityId],
            organizationUnitIds: [$primaryOrganizationUnitId],
            primaryOrganizationUnitId: $primaryOrganizationUnitId,
            selectedScope: ['scope_type' => 'facility', 'scope_id' => $otherFacilityId],
            sessionRestricted: false,
        );

        $scope = AuditApi::scope($principal);
        $this->assertSame(
            $otherFacilityId,
            $scope['facility_id'],
            'Selected facility scope must win over the canonical fallback.',
        );
    }
}

final class AuditAuthorizationSessionMiddleware
{
    public function __construct(private readonly ResolvePrincipalContext $principals) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->principals->resolve($request) === null) {
            return AuditApi::problem(
                401,
                'authentication-required',
                'Unauthorized',
                'Authentication is required.',
                AuditApi::correlationId($request),
            );
        }

        return $next($request);
    }
}

final class AuditAuthorizationPrincipalResolver implements ResolvePrincipalContext
{
    public function resolve(Request $request): PrincipalContext
    {
        return new PrincipalContext(
            userId: AuditAuthorizationTest::USER_ID,
            personId: null,
            accountStatus: 'active',
            clusterIds: [],
            facilityIds: [AuditAuthorizationTest::FACILITY_ID],
            organizationUnitIds: [AuditAuthorizationTest::UNIT_ID],
            primaryOrganizationUnitId: AuditAuthorizationTest::UNIT_ID,
            selectedScope: ['scope_type' => 'facility', 'scope_id' => AuditAuthorizationTest::FACILITY_ID],
            sessionRestricted: false,
        );
    }

    public function resolveSelectedScope(Request $request): ?array
    {
        return $this->resolve($request)->selectedScope;
    }

    public function persistSelectedScope(Request $request, string $scopeType, string $scopeId): void {}
}

final class AuditAuthorizationDecisionEngine implements DecideAccess
{
    public bool $allowCollection = true;

    /** @var list<string> */
    public array $deniedRecordIds = [];

    /** @var list<string> */
    public array $maskedRecordIds = [];

    /** @var list<array{actor: array<string, mixed>, capability: string, facts: RecordFacts}> */
    public array $calls = [];

    public function __construct(
        private readonly string $allowedFacilityId,
        private readonly string $allowedOrganizationUnitId,
        private readonly string $decisionId,
    ) {}

    /**
     * Test doubles persist nothing, so the read-side evaluation IS decide().
     */
    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        assert($facts instanceof RecordFacts);
        $this->calls[] = compact('actor', 'capability', 'facts');
        $isCollection = $facts->resourceType === 'audit_event_collection';
        $scopeAllowed = ($facts->ownerFacilityId === null || $facts->ownerFacilityId === $this->allowedFacilityId)
            && ($facts->organizationUnitId === null || $facts->organizationUnitId === $this->allowedOrganizationUnitId)
            && ($facts->sharedUnitIds === [] || in_array($this->allowedOrganizationUnitId, $facts->sharedUnitIds, true));
        $allowed = $isCollection
            ? $this->allowCollection
            : $scopeAllowed && ! in_array((string) $facts->recordId, $this->deniedRecordIds, true);
        $masked = ! $isCollection && in_array((string) $facts->recordId, $this->maskedRecordIds, true);

        return new AccessDecision(
            decision: $allowed ? 'allow' : 'deny',
            action: $capability,
            resourceType: $facts->resourceType,
            reasonCodes: [$allowed ? 'authorized' : 'concealed'],
            policyVersion: 'audit-authorization-test-v1',
            factsVersion: 'audit-authorization-test-v1',
            classification: $facts->classification,
            decisionId: $allowed ? $this->decisionId : null,
            allowedActions: $allowed ? ['audit.event.read'] : [],
            fieldAccess: $masked ? [
                'payload.display_label' => 'masked',
                'payload.hidden_note' => 'hidden',
            ] : [],
        );
    }
}
