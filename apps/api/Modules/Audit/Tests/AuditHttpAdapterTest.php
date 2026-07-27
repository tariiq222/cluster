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

final class AuditHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const USER_ID = '018f6f7d-0c00-7000-8000-000000000401';

    private const OTHER_USER_ID = '018f6f7d-0c00-7000-8000-000000000402';

    private const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000403';

    private const OTHER_FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000404';

    private const UNIT_ID = '018f6f7d-0c00-7000-8000-000000000405';

    private const OTHER_UNIT_ID = '018f6f7d-0c00-7000-8000-000000000406';

    private const ACTOR_ID = '018f6f7d-0c00-7000-8000-000000000407';

    private const SUBJECT_ID = '018f6f7d-0c00-7000-8000-000000000408';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000409';

    private const DECISION_ID = '018f6f7d-0c00-7000-8000-000000000410';

    private AuditHttpDecisionEngine $decisions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ResolvePrincipalContext::class, new AuditHttpPrincipalResolver(
            self::USER_ID,
            self::FACILITY_ID,
            self::UNIT_ID,
        ));
        $this->decisions = new AuditHttpDecisionEngine(self::DECISION_ID);
        $this->app->instance(DecideAccess::class, $this->decisions);

        Route::middleware(AuditHttpSessionMiddleware::class)
            ->get(AuditApi::ROUTE_LIST, ListAuditEventsController::class)
            ->name('audit.test.events.index');
        Route::middleware(AuditHttpSessionMiddleware::class)
            ->get(AuditApi::ROUTE_GET, GetAuditEventController::class)
            ->name('audit.test.events.show');
    }

    public function test_session_and_base_capability_precede_query_validation_and_problems_are_canonical(): void
    {
        $this->getJson(AuditApi::ROUTE_LIST, $this->headers(authenticated: false))
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/authentication-required')
            ->assertJsonPath('status', 401)
            ->assertJsonMissingPath('errors');
        $this->assertSame([], $this->decisions->calls);

        $this->decisions->allowCollection = false;
        $this->getJson(AuditApi::ROUTE_LIST.'?unknown=value&limit=not-an-integer', $this->headers())
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/access-denied')
            ->assertJsonPath('detail', 'Access denied.');

        $this->assertCount(1, $this->decisions->calls);
        $this->assertSame('audit.event.read', $this->decisions->calls[0]['capability']);
        $this->assertSame('audit_event_collection', $this->decisions->calls[0]['facts']->resourceType);
    }

    public function test_correlation_unknown_query_and_limit_contract_are_strict_and_safe(): void
    {
        $this->getJson(AuditApi::ROUTE_LIST, $this->headers(correlationId: strtoupper(self::CORRELATION_ID)))
            ->assertBadRequest()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-correlation-id')
            ->assertJsonMissingPath('correlation_id');

        $this->getJson(AuditApi::ROUTE_LIST.'?facility_id='.self::FACILITY_ID, $this->headers())
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-query-parameter')
            ->assertJsonMissing(['facility_id' => self::FACILITY_ID]);

        foreach ([0, 101] as $invalidLimit) {
            $this->getJson(AuditApi::ROUTE_LIST.'?limit='.$invalidLimit, $this->headers())
                ->assertBadRequest()
                ->assertJsonPath('type', 'https://cluster.example/problems/invalid-pagination');
        }

        foreach ([1, 100] as $validLimit) {
            $this->getJson(AuditApi::ROUTE_LIST.'?limit='.$validLimit, $this->headers())
                ->assertOk()
                ->assertJsonPath('items', [])
                ->assertJsonPath('next_cursor', null);
        }
    }

    public function test_authenticated_cursor_rejects_tamper_principal_scope_filter_and_limit_mismatch(): void
    {
        $this->insertEvent('018f6f7d-0c00-7000-8000-000000000431', 1, '2026-07-27 12:00:00.002');
        $this->insertEvent('018f6f7d-0c00-7000-8000-000000000432', 2, '2026-07-27 12:00:00.001');
        $this->insertEvent('018f6f7d-0c00-7000-8000-000000000433', 3, '2026-07-27 12:00:00.000');

        $first = $this->getJson(
            AuditApi::ROUTE_LIST.'?limit=1&source_module=documents',
            $this->headers(),
        )->assertOk();
        $cursor = (string) $first->json('next_cursor');
        $this->assertNotSame('', $cursor);
        $this->assertStringNotContainsString('018f6f7d', $cursor);

        $tampered = substr($cursor, 0, -1).($cursor[-1] === 'a' ? 'b' : 'a');
        $this->assertInvalidCursor($tampered, $this->headers(), 'limit=1&source_module=documents');
        $this->assertInvalidCursor($cursor, $this->headers(userId: self::OTHER_USER_ID), 'limit=1&source_module=documents');
        $this->assertInvalidCursor($cursor, $this->headers(facilityId: self::OTHER_FACILITY_ID), 'limit=1&source_module=documents');
        $this->assertInvalidCursor($cursor, $this->headers(organizationUnitIds: [self::OTHER_UNIT_ID]), 'limit=1&source_module=documents');
        $this->assertInvalidCursor($cursor, $this->headers(), 'limit=1&source_module=documents&action=document.deleted');
        $this->assertInvalidCursor($cursor, $this->headers(), 'limit=2&source_module=documents');
    }

    public function test_collection_uses_limit_plus_one_stable_recorded_at_id_order_link_and_safe_shape(): void
    {
        $lowerId = '018f6f7d-0c00-7000-8000-000000000441';
        $higherId = '018f6f7d-0c00-7000-8000-000000000442';
        $olderId = '018f6f7d-0c00-7000-8000-000000000443';
        $this->insertEvent($lowerId, 1, '2026-07-27 12:00:00.000');
        $this->insertEvent($higherId, 2, '2026-07-27 12:00:00.000');
        $this->insertEvent($olderId, 3, '2026-07-27 11:59:59.999');

        $response = $this->getJson(AuditApi::ROUTE_LIST.'?limit=2', $this->headers())
            ->assertOk()
            ->assertHeader('X-Correlation-ID', self::CORRELATION_ID)
            ->assertJsonPath('items.0.event_id', $higherId)
            ->assertJsonPath('items.1.event_id', $lowerId)
            ->assertJsonMissingPath('total')
            ->assertJsonMissingPath('items.0.request_hash')
            ->assertJsonMissingPath('items.0.event_hash')
            ->assertJsonMissingPath('items.0.previous_hash')
            ->assertJsonMissingPath('items.0.integrity_key_version');

        $cursor = (string) $response->json('next_cursor');
        $this->assertNotSame('', $cursor);
        $this->assertStringContainsString('rel="next"', (string) $response->headers->get('Link'));
        $this->assertStringContainsString(rawurlencode($cursor), (string) $response->headers->get('Link'));

        $this->getJson(AuditApi::ROUTE_LIST.'?limit=2&cursor='.rawurlencode($cursor), $this->headers())
            ->assertOk()
            ->assertJsonPath('items.0.event_id', $olderId)
            ->assertJsonPath('next_cursor', null);
    }

    public function test_detail_returns_redacted_projected_dto_and_missing_is_concealed(): void
    {
        $eventId = '018f6f7d-0c00-7000-8000-000000000451';
        $this->insertEvent($eventId, 1, '2026-07-27 12:00:00.000', [
            'facility_id' => self::FACILITY_ID,
            'organization_unit_id' => self::UNIT_ID,
            'token' => 'raw-secret-token',
            'patient_label' => 'private patient label',
            'hidden_note' => 'never return this',
        ]);
        $this->decisions->maskedRecordIds[] = $eventId;

        $this->getJson(str_replace('{eventId}', $eventId, AuditApi::ROUTE_GET), $this->headers())
            ->assertOk()
            ->assertHeader('X-Correlation-ID', self::CORRELATION_ID)
            ->assertJsonPath('data.event_id', $eventId)
            ->assertJsonPath('data.context.token', '[REDACTED]')
            ->assertJsonPath('data.context.patient_label', '[REDACTED]')
            ->assertJsonMissingPath('data.context.hidden_note')
            ->assertJsonPath('data.access_decision_id', self::DECISION_ID)
            ->assertJsonPath('data.allowed_actions.0', 'audit.event.read')
            ->assertJsonPath('data.integrity_status', 'unverified');

        $missingId = '018f6f7d-0c00-7000-8000-000000000452';
        $this->getJson(str_replace('{eventId}', $missingId, AuditApi::ROUTE_GET), $this->headers())
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/audit-event-not-found')
            ->assertJsonPath('correlation_id', self::CORRELATION_ID);
    }

    /**
     * @param  list<string>  $organizationUnitIds
     * @return array<string, string>
     */
    private function headers(
        bool $authenticated = true,
        string $correlationId = self::CORRELATION_ID,
        string $userId = self::USER_ID,
        string $facilityId = self::FACILITY_ID,
        array $organizationUnitIds = [self::UNIT_ID],
    ): array {
        return [
            'X-Correlation-ID' => $correlationId,
            'X-Test-Audit-Authenticated' => $authenticated ? '1' : '0',
            'X-Test-Audit-User' => $userId,
            'X-Test-Audit-Facility' => $facilityId,
            'X-Test-Audit-Organization-Units' => implode(',', $organizationUnitIds),
        ];
    }

    /** @param array<string, mixed> $context */
    private function insertEvent(string $id, int $sequence, string $recordedAt, array $context = []): void
    {
        DB::table('audit_events')->insert([
            'id' => $id,
            'request_hash' => hash('sha256', 'request-'.$sequence),
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
            'previous_hash' => $sequence === 1 ? null : hash('sha256', 'event-'.($sequence - 1)),
            'event_hash' => hash('sha256', 'event-'.$sequence),
            'integrity_key_version' => 'v1',
        ]);
    }

    /** @param array<string, string> $headers */
    private function assertInvalidCursor(string $cursor, array $headers, string $query): void
    {
        $this->getJson(AuditApi::ROUTE_LIST.'?'.$query.'&cursor='.rawurlencode($cursor), $headers)
            ->assertBadRequest()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-pagination')
            ->assertJsonMissing(['cursor' => $cursor]);
    }
}

final class AuditHttpSessionMiddleware
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

final class AuditHttpPrincipalResolver implements ResolvePrincipalContext
{
    public function __construct(
        private readonly string $defaultUserId,
        private readonly string $defaultFacilityId,
        private readonly string $defaultOrganizationUnitId,
    ) {}

    public function resolve(Request $request): ?PrincipalContext
    {
        if ($request->header('X-Test-Audit-Authenticated') !== '1') {
            return null;
        }

        $userId = (string) $request->header('X-Test-Audit-User', $this->defaultUserId);
        $facilityId = (string) $request->header('X-Test-Audit-Facility', $this->defaultFacilityId);
        $units = array_values(array_filter(explode(',', (string) $request->header(
            'X-Test-Audit-Organization-Units',
            $this->defaultOrganizationUnitId,
        ))));

        return new PrincipalContext(
            userId: $userId,
            personId: null,
            accountStatus: 'active',
            clusterIds: [],
            facilityIds: [$facilityId],
            organizationUnitIds: $units,
            primaryOrganizationUnitId: $units[0] ?? null,
            selectedScope: ['scope_type' => 'facility', 'scope_id' => $facilityId],
            sessionRestricted: false,
        );
    }

    public function resolveSelectedScope(Request $request): ?array
    {
        return $this->resolve($request)?->selectedScope;
    }

    public function persistSelectedScope(Request $request, string $scopeType, string $scopeId): void {}
}

final class AuditHttpDecisionEngine implements DecideAccess
{
    public bool $allowCollection = true;

    /** @var list<string> */
    public array $deniedRecordIds = [];

    /** @var list<string> */
    public array $maskedRecordIds = [];

    /** @var list<array{actor: array<string, mixed>, capability: string, facts: RecordFacts}> */
    public array $calls = [];

    public function __construct(private readonly string $decisionId) {}

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        assert($facts instanceof RecordFacts);
        $this->calls[] = compact('actor', 'capability', 'facts');
        $isCollection = $facts->resourceType === 'audit_event_collection';
        $allowed = $isCollection
            ? $this->allowCollection
            : ! in_array((string) $facts->recordId, $this->deniedRecordIds, true);
        $masked = ! $isCollection && in_array((string) $facts->recordId, $this->maskedRecordIds, true);

        return new AccessDecision(
            decision: $allowed ? 'allow' : 'deny',
            action: $capability,
            resourceType: $facts->resourceType,
            reasonCodes: [$allowed ? 'audit_test_allowed' : 'audit_test_denied'],
            policyVersion: 'audit-http-test-v1',
            factsVersion: 'audit-http-test-v1',
            classification: $facts->classification,
            decisionId: $allowed ? $this->decisionId : null,
            allowedActions: $allowed ? ['audit.event.read'] : [],
            fieldAccess: $masked ? [
                'payload.patient_label' => 'masked',
                'payload.hidden_note' => 'hidden',
            ] : [],
        );
    }
}
