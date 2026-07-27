<?php

declare(strict_types=1);

namespace Modules\Audit\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Audit\Domain\AuditContextProjection;
use Modules\Audit\Domain\AuditEventCanonicalizer;
use Modules\Audit\Domain\AuditExportProjection;
use Modules\Audit\Domain\AuditExportSection8Columns;
use Modules\Audit\Domain\AuditIntegrityHasher;
use Modules\Audit\Domain\AuditRetentionPolicy;
use Modules\Audit\Domain\SensitiveValueRedactor;
use Modules\Audit\Events\AuditExportCompletedV1;
use Modules\Audit\Features\CreateAuditExport\Handler\CreateAuditExportHandler;
use Modules\Audit\Features\CreateAuditExport\Http\CreateAuditExportController;
use Modules\Audit\Features\DownloadAuditExport\Handler\DownloadAuditExportHandler;
use Modules\Audit\Features\DownloadAuditExport\Http\DownloadAuditExportController;
use Modules\Audit\Features\GetAuditExport\Http\GetAuditExportController;
use Modules\Audit\Http\AuditApi;
use Modules\Audit\Infrastructure\Persistence\AuditExportReadStore;
use Modules\Audit\Infrastructure\Persistence\AuditExportRepository;
use Modules\Audit\Infrastructure\Persistence\AuditIdempotencyStore;
use Modules\Audit\Infrastructure\Persistence\DatabaseRecordAuditEvent;
use Modules\Audit\Tests\Support\AuditExportCsrfMiddleware;
use Modules\Audit\Tests\Support\AuditExportSessionMiddleware;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\PrincipalContext;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Shared\Contracts\TransactionalOutbox;
use Tests\TestCase;

final class AuditExportTest extends TestCase
{
    use RefreshDatabase;

    private const USER_ID = '018f6f7d-0c00-7000-8000-000000000601';

    private const OTHER_USER_ID = '018f6f7d-0c00-7000-8000-000000000602';

    private const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000603';

    private const OTHER_FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000604';

    private const UNIT_ID = '018f6f7d-0c00-7000-8000-000000000605';

    private const OTHER_UNIT_ID = '018f6f7d-0c00-7000-8000-000000000606';

    private const ACTOR_ID = '018f6f7d-0c00-7000-8000-000000000607';

    private const SUBJECT_ID = '018f6f7d-0c00-7000-8000-000000000608';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000609';

    private const DECISION_ID = '018f6f7d-0c00-7000-8000-000000000610';

    private AuditExportDecisionEngine $decisions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ResolvePrincipalContext::class, new AuditExportPrincipalResolver(
            self::USER_ID,
            self::FACILITY_ID,
            self::UNIT_ID,
        ));
        $this->decisions = new AuditExportDecisionEngine(self::DECISION_ID);
        $this->app->instance(DecideAccess::class, $this->decisions);

        $this->app->singleton(SensitiveValueRedactor::class);
        $contexts = new AuditContextProjection($this->app->make(SensitiveValueRedactor::class));
        $this->app->instance(AuditContextProjection::class, $contexts);
        $projection = new AuditExportProjection($this->app->make(SensitiveValueRedactor::class));
        $this->app->instance(AuditExportProjection::class, $projection);
        $this->app->instance(AuditExportReadStore::class, new AuditExportReadStore($this->decisions, $contexts));

        $hasher = new AuditIntegrityHasher(['v1' => str_repeat('test-key-material-must-be-long-enough', 2)]);
        $retention = new AuditRetentionPolicy(2555);
        $canonicalizer = new AuditEventCanonicalizer;
        $this->app->instance(AuditEventCanonicalizer::class, $canonicalizer);

        $recorder = new DatabaseRecordAuditEvent(
            $this->app->make(TransactionalOutbox::class),
            $this->app->make(SensitiveValueRedactor::class),
            $hasher,
            $retention,
            $canonicalizer,
            'v1',
        );

        $exports = new AuditExportRepository($this->app->make(TransactionalOutbox::class));
        $this->app->instance(AuditExportRepository::class, $exports);

        $idempotency = new AuditIdempotencyStore;
        $this->app->instance(AuditIdempotencyStore::class, $idempotency);

        $handler = new CreateAuditExportHandler(
            $recorder,
            $exports,
            $this->app->make(AuditExportReadStore::class),
            $idempotency,
            7,
            90,
        );
        $this->app->instance(CreateAuditExportHandler::class, $handler);

        $downloadHandler = new DownloadAuditExportHandler(
            $this->app->make(ResolvePrincipalContext::class),
            $this->decisions,
            $exports,
            $this->app->make(AuditExportReadStore::class),
            $projection,
            $recorder,
        );
        $this->app->instance(DownloadAuditExportHandler::class, $downloadHandler);

        Route::middleware(AuditExportSessionMiddleware::class)
            ->get(AuditApi::ROUTE_GET_EXPORT, GetAuditExportController::class)
            ->name('audit.export-test.exports.show');
        Route::middleware([AuditExportSessionMiddleware::class, AuditExportCsrfMiddleware::class])
            ->post(AuditApi::ROUTE_CREATE_EXPORT, CreateAuditExportController::class)
            ->name('audit.export-test.exports.store');
        Route::middleware(AuditExportSessionMiddleware::class)
            ->get(AuditApi::ROUTE_DOWNLOAD_EXPORT, DownloadAuditExportController::class)
            ->name('audit.export-test.exports.download');
    }

    public function test_session_and_capability_precede_body_validation_and_idempotency_key_requirement(): void
    {
        $this->postJson(AuditApi::ROUTE_CREATE_EXPORT, [
            'format' => 'csv',
            'filters' => [],
            'reason' => 'investigation',
        ], $this->headers(authenticated: false))
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/authentication-required')
            ->assertJsonMissingPath('idempotency_key');
        $this->assertSame([], $this->decisions->calls);

        $this->decisions->allowExport = false;
        $this->postJson(AuditApi::ROUTE_CREATE_EXPORT, [], $this->headers())
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/access-denied')
            ->assertJsonPath('detail', 'Access denied.');
        $this->assertCount(1, $this->decisions->calls);

        // Without capability, missing idempotency key is NOT reported.
        $this->assertSame('audit.event.export', $this->decisions->calls[0]['capability']);
        $this->assertSame('audit_export', $this->decisions->calls[0]['facts']->resourceType);
    }

    public function test_csrf_token_is_required_before_body_validation(): void
    {
        $this->postJson(AuditApi::ROUTE_CREATE_EXPORT, [
            'format' => 'csv',
            'filters' => [],
            'reason' => 'investigation',
        ], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'Idempotency-Key' => 'csrf-test',
            'X-Test-Audit-Authenticated' => '1',
        ])
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/csrf-failed');
    }

    public function test_correlation_id_and_idempotency_key_contract_are_strict_and_safe(): void
    {
        $this->postJson(AuditApi::ROUTE_CREATE_EXPORT, [
            'format' => 'csv',
            'filters' => [],
            'reason' => 'investigation',
        ], $this->headers(correlationId: strtoupper(self::CORRELATION_ID)))
            ->assertBadRequest()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-correlation-id')
            ->assertJsonMissingPath('correlation_id');

        // Missing Idempotency-Key.
        $noKey = $this->headers();
        unset($noKey['Idempotency-Key']);
        $this->postJson(AuditApi::ROUTE_CREATE_EXPORT, [], $noKey)
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-idempotency-key')
            ->assertJsonMissingPath('idempotency_key');
    }

    public function test_create_returns_201_with_strong_etag_and_atomic_descriptor_creation_activity_and_completion_event(): void
    {
        $this->insertEvent('018f6f7d-0c00-7000-8000-000000000621', 1, '2026-07-27 00:30:00.000');

        $created = $this->postJson(AuditApi::ROUTE_CREATE_EXPORT, [
            'format' => 'csv',
            'filters' => ['source_module' => 'documents'],
            'reason' => 'incident 2026-Q3 review',
        ], $this->headers())
            ->assertCreated()
            ->assertHeader('X-Correlation-ID', self::CORRELATION_ID);

        $descriptor = $created->json('data');
        $this->assertNotNull($descriptor['id']);
        $this->assertSame('csv', $descriptor['format']);
        $this->assertSame(self::USER_ID, $descriptor['principal_id']);
        $this->assertSame(self::FACILITY_ID, $descriptor['facility_id']);
        $this->assertSame(AuditExportRepository::STATUS_READY, $descriptor['status']);
        $this->assertSame(['source_module' => 'documents'], $descriptor['query']);
        $this->assertSame(1, $descriptor['event_count']);
        $this->assertSame('"'.$descriptor['id'].'"', $created->headers->get('ETag'));

        $this->assertDatabaseCount('audit_export_jobs', 1);
        $this->assertDatabaseHas('audit_export_jobs', [
            'id' => $descriptor['id'],
            'principal_id' => self::USER_ID,
            'facility_id' => self::FACILITY_ID,
            'status' => 'ready',
            'format' => 'csv',
            'event_count' => 1,
        ]);

        // Exactly one AuditExportCompletedV1 outbox row, exactly one
        // creation activity in audit_events.
        $this->assertSame(1, DB::table('outbox_events')
            ->where('event_type', AuditExportCompletedV1::EVENT_TYPE)
            ->where('aggregate_id', $descriptor['id'])
            ->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'audit.export.created')
            ->where('subject_id', $descriptor['id'])
            ->count());
    }

    public function test_equal_idempotent_replay_returns_stored_descriptor_with_strong_etag_and_no_new_outbox_or_activity(): void
    {
        $this->insertEvent('018f6f7d-0c00-7000-8000-000000000631', 1, '2026-07-27 00:30:00.000');

        $first = $this->postJson(AuditApi::ROUTE_CREATE_EXPORT, [
            'format' => 'csv',
            'filters' => [],
            'reason' => 'incident 2026-Q3 review',
        ], $this->headers())->assertCreated();
        $firstId = (string) $first->json('data.id');

        $replay = $this->postJson(AuditApi::ROUTE_CREATE_EXPORT, [
            'format' => 'csv',
            'filters' => [],
            'reason' => 'incident 2026-Q3 review',
        ], $this->headers())->assertCreated();

        $this->assertSame($firstId, (string) $replay->json('data.id'));
        $this->assertSame('"'.$firstId.'"', $replay->headers->get('ETag'));

        // No new completion event and no new creation activity.
        $this->assertSame(1, DB::table('outbox_events')
            ->where('event_type', AuditExportCompletedV1::EVENT_TYPE)
            ->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'audit.export.created')
            ->count());
    }

    public function test_mismatched_idempotency_replay_returns_typed_409(): void
    {
        $this->postJson(AuditApi::ROUTE_CREATE_EXPORT, [
            'format' => 'csv',
            'filters' => [],
            'reason' => 'incident A',
        ], $this->headers())->assertCreated();

        $this->postJson(AuditApi::ROUTE_CREATE_EXPORT, [
            'format' => 'ndjson',
            'filters' => [],
            'reason' => 'incident A',
        ], $this->headers())
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/idempotency-conflict')
            ->assertJsonPath('detail', 'Idempotency-Key was already used for a different request.');
    }

    public function test_unknown_filter_key_or_oversized_reason_is_rejected_with_safe_problem(): void
    {
        $headers = $this->headers(idempotencyKey: 'unknown-filter');

        $this->postJson(AuditApi::ROUTE_CREATE_EXPORT, [
            'format' => 'csv',
            'filters' => ['reason' => 'should-not-be-allowed'],
            'reason' => 'investigation',
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-export-payload')
            ->assertJsonMissing(['filters' => ['reason' => 'should-not-be-allowed']]);

        $headers['Idempotency-Key'] = 'oversized-reason';
        $this->postJson(AuditApi::ROUTE_CREATE_EXPORT, [
            'format' => 'csv',
            'filters' => [],
            'reason' => str_repeat('x', 501),
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-export-reason')
            ->assertJsonMissingPath('reason');
    }

    public function test_show_returns_descriptor_for_owner_only_with_strong_etag_and_conceals_mismatch(): void
    {
        $id = $this->createReadyDescriptor(self::FACILITY_ID);

        // Foreign principal: identical 404 to missing-export.
        $foreign = $this->getJson(str_replace('{exportId}', $id, AuditApi::ROUTE_GET_EXPORT), $this->headers(userId: self::OTHER_USER_ID, facilityId: self::OTHER_FACILITY_ID, organizationUnitIds: [self::OTHER_UNIT_ID]))
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json');
        $foreign->assertJsonPath('type', 'https://cluster.example/problems/audit-export-not-found')
            ->assertJsonPath('detail', 'The audit export was not found.');

        $missing = $this->getJson(str_replace('{exportId}', '018f6f7d-0c00-7000-8000-000000000999', AuditApi::ROUTE_GET_EXPORT), $this->headers(userId: self::OTHER_USER_ID, facilityId: self::OTHER_FACILITY_ID, organizationUnitIds: [self::OTHER_UNIT_ID]))
            ->assertNotFound();

        $this->assertSame($foreign->getContent(), $missing->getContent());

        // Owner gets the descriptor + ETag.
        $owner = $this->getJson(str_replace('{exportId}', $id, AuditApi::ROUTE_GET_EXPORT), $this->headers())
            ->assertOk()
            ->assertHeader('X-Correlation-ID', self::CORRELATION_ID)
            ->assertHeader('ETag', '"'.$id.':1"');
        $owner->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.principal_id', self::USER_ID)
            ->assertJsonPath('data.facility_id', self::FACILITY_ID)
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonMissingPath('data.subject_id')
            ->assertJsonMissingPath('data.user_id');

        $payload = (string) $owner->getContent();
        $this->assertStringNotContainsString('must-not-leak-owner-text', $payload);
    }

    public function test_descriptor_for_other_principal_returns_identical_404_with_missing(): void
    {
        $missing = $this->getJson(str_replace('{exportId}', '018f6f7d-0c00-7000-8000-000000000999', AuditApi::ROUTE_GET_EXPORT), $this->headers())
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json');
        $foreignDescriptorId = $this->createReadyDescriptor(self::OTHER_FACILITY_ID);
        $foreign = $this->getJson(str_replace('{exportId}', $foreignDescriptorId, AuditApi::ROUTE_GET_EXPORT), $this->headers())
            ->assertNotFound();

        $this->assertSame($missing->getContent(), $foreign->getContent());
    }

    public function test_create_with_invalid_format_or_empty_reason_returns_typed_422(): void
    {
        $this->postJson(AuditApi::ROUTE_CREATE_EXPORT, [
            'format' => 'pdf',
            'filters' => [],
            'reason' => 'investigation',
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-export-format');

        $this->postJson(AuditApi::ROUTE_CREATE_EXPORT, [
            'format' => 'csv',
            'filters' => [],
            'reason' => '',
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-export-reason');
    }

    public function test_download_re_authorizes_each_call_and_streams_csv_with_formula_escaping_and_section_eight_columns(): void
    {
        $this->insertEvent('018f6f7d-0c00-7000-8000-000000000641', 1, '2026-07-27 00:30:00.000', [
            'formula' => '=SUM(1+1)',
            'plus' => '+1',
            'minus' => '-1',
            'at' => '@cmd',
            'comma' => 'a,b',
            'quote' => 'a"b',
        ]);
        $this->insertEvent('018f6f7d-0c00-7000-8000-000000000642', 2, '2026-07-27 00:30:00.001', [
            'token' => 'must-not-leave-audit',
        ]);

        $descriptorId = $this->createReadyDescriptor(self::FACILITY_ID, format: 'csv');

        $first = $this->getStreamed(
            str_replace('{exportId}', $descriptorId, AuditApi::ROUTE_DOWNLOAD_EXPORT),
            $this->headers(),
        );

        $this->assertSame(200, $first['status']);
        $this->assertStringStartsWith('text/csv', $first['headers']['Content-Type'] ?? '');
        $this->assertStringContainsString('no-store', $first['headers']['Cache-Control']);
        $this->assertStringContainsString('attachment; filename="audit-export-', $first['headers']['Content-Disposition'] ?? '');
        $this->assertStringNotContainsString('must-not-leave-audit', $first['body']);

        // Header line matches Section 8 columns exactly.
        $body = $first['body'];
        $firstLineEnd = strpos($body, "\r\n");
        $headerLine = substr($body, 0, (int) $firstLineEnd);
        $this->assertSame(implode(',', AuditExportSection8Columns::COLUMNS), $headerLine);

        // Every CSV cell whose first byte is spreadsheet-executable is escaped.
        $projection = new AuditExportProjection($this->app->make(SensitiveValueRedactor::class));
        foreach (['=SUM(1+1)', '+1', '-1', '@cmd'] as $formula) {
            $formulaRow = array_fill_keys(AuditExportSection8Columns::COLUMNS, '');
            $formulaRow['context'] = $formula;
            $this->assertStringContainsString("'".$formula, $projection->toCsvLine($formulaRow));
        }
        // Comma + quote escaping stays correct for scalar CSV cells.
        $csvRow = array_fill_keys(AuditExportSection8Columns::COLUMNS, '');
        $csvRow['context'] = 'a,b';
        $this->assertStringContainsString('"a,b"', $projection->toCsvLine($csvRow));
        $csvRow['context'] = 'a"b';
        $this->assertStringContainsString('"a""b"', $projection->toCsvLine($csvRow));
        // Sensitive value redaction at read time.
        $this->assertStringNotContainsString('must-not-leave-audit', $body);
        $this->assertStringContainsString('[REDACTED]', $body);

        // Exactly one download-attempt activity, zero completion events.
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'audit.export.downloaded')
            ->where('subject_id', $descriptorId)
            ->count());
        $this->assertSame(0, DB::table('outbox_events')
            ->where('event_type', AuditExportCompletedV1::EVENT_TYPE)
            ->where('aggregate_id', $descriptorId)
            ->count());

        // Repeated downloads return deterministic bytes.
        $second = $this->getStreamed(
            str_replace('{exportId}', $descriptorId, AuditApi::ROUTE_DOWNLOAD_EXPORT),
            $this->headers(),
        );
        $this->assertSame($first['body'], $second['body']);
    }

    public function test_download_with_ndjson_format_streams_one_valid_json_object_per_line(): void
    {
        $this->insertEvent('018f6f7d-0c00-7000-8000-000000000651', 1, '2026-07-27 00:30:00.000');
        $this->insertEvent('018f6f7d-0c00-7000-8000-000000000652', 2, '2026-07-27 00:30:00.001');

        $descriptorId = $this->createReadyDescriptor(self::FACILITY_ID, format: 'ndjson');
        $streamed = $this->getStreamed(
            str_replace('{exportId}', $descriptorId, AuditApi::ROUTE_DOWNLOAD_EXPORT),
            $this->headers(),
        );

        $this->assertSame(200, $streamed['status']);
        $this->assertStringStartsWith('application/x-ndjson', $streamed['headers']['Content-Type'] ?? '');
        $this->assertStringContainsString('no-store', $streamed['headers']['Cache-Control']);
        $lines = array_filter(explode("\n", $streamed['body']));
        $this->assertCount(2, $lines);
        $this->assertSame(
            '018f6f7d-0c00-7000-8000-000000000652',
            json_decode($lines[0], true, 16, JSON_THROW_ON_ERROR)['event_id'] ?? null,
            'Export rows must use the canonical (recorded_at DESC, id DESC) order.',
        );
        foreach ($lines as $line) {
            $decoded = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
            $this->assertSame(
                AuditExportSection8Columns::COLUMNS,
                array_keys($decoded),
                'NDJSON keys must mirror the Section 8 column order.',
            );
        }
    }

    public function test_descriptor_count_and_download_exclude_rows_denied_by_per_event_authorization(): void
    {
        $allowedId = '018f6f7d-0c00-7000-8000-000000000653';
        $deniedId = '018f6f7d-0c00-7000-8000-000000000654';
        $this->insertEvent($allowedId, 1, '2026-07-27 00:30:00.000');
        $this->insertEvent($deniedId, 2, '2026-07-27 00:30:00.001');
        $this->decisions->deniedRecordIds = [$deniedId];

        $created = $this->postJson(AuditApi::ROUTE_CREATE_EXPORT, [
            'format' => 'ndjson',
            'filters' => [],
            'reason' => 'authorized rows only',
        ], $this->headers(idempotencyKey: 'authorized-rows'))
            ->assertCreated()
            ->assertJsonPath('data.event_count', 1);

        $descriptorId = (string) $created->json('data.id');
        $streamed = $this->getStreamed(
            str_replace('{exportId}', $descriptorId, AuditApi::ROUTE_DOWNLOAD_EXPORT),
            $this->headers(),
        );

        $this->assertSame(200, $streamed['status']);
        $this->assertStringContainsString($allowedId, $streamed['body']);
        $this->assertStringNotContainsString($deniedId, $streamed['body']);
    }

    public function test_descriptor_count_and_download_honor_occurred_time_filters(): void
    {
        $beforeId = '018f6f7d-0c00-7000-8000-000000000655';
        $insideId = '018f6f7d-0c00-7000-8000-000000000656';
        $this->insertEvent($beforeId, 1, '2026-07-27 00:30:00.000');
        $this->insertEvent(
            $insideId,
            2,
            '2026-07-27 00:30:00.001',
            occurredAt: '2026-07-27 01:00:00.000',
        );

        $created = $this->postJson(AuditApi::ROUTE_CREATE_EXPORT, [
            'format' => 'ndjson',
            'filters' => ['occurred_from' => '2026-07-27T00:30:00.000Z'],
            'reason' => 'time-bounded export',
        ], $this->headers(idempotencyKey: 'occurred-range'))
            ->assertCreated()
            ->assertJsonPath('data.event_count', 1);

        $streamed = $this->getStreamed(
            str_replace(
                '{exportId}',
                (string) $created->json('data.id'),
                AuditApi::ROUTE_DOWNLOAD_EXPORT,
            ),
            $this->headers(),
        );

        $this->assertStringNotContainsString($beforeId, $streamed['body']);
        $this->assertStringContainsString($insideId, $streamed['body']);
    }

    public function test_download_applies_authorization_field_access_before_export_projection(): void
    {
        $eventId = '018f6f7d-0c00-7000-8000-000000000657';
        $this->insertEvent($eventId, 1, '2026-07-27 00:30:00.000', [
            'public_note' => 'visible-value',
            'internal_note' => 'must-be-hidden-by-field-access',
        ]);
        $this->decisions->fieldAccess = ['payload.internal_note' => 'hidden'];

        $created = $this->postJson(AuditApi::ROUTE_CREATE_EXPORT, [
            'format' => 'ndjson',
            'filters' => [],
            'reason' => 'field-filtered export',
        ], $this->headers(idempotencyKey: 'field-access'))->assertCreated();

        $streamed = $this->getStreamed(
            str_replace(
                '{exportId}',
                (string) $created->json('data.id'),
                AuditApi::ROUTE_DOWNLOAD_EXPORT,
            ),
            $this->headers(),
        );

        $this->assertStringContainsString('visible-value', $streamed['body']);
        $this->assertStringNotContainsString('internal_note', $streamed['body']);
        $this->assertStringNotContainsString('must-be-hidden-by-field-access', $streamed['body']);
    }

    public function test_fixed_snapshot_upper_bound_excludes_later_recorded_events(): void
    {
        $this->insertEvent('018f6f7d-0c00-7000-8000-000000000661', 1, '2026-07-27 00:30:00.000');
        $this->insertEvent('018f6f7d-0c00-7000-8000-000000000662', 2, '2026-07-27 00:31:00.000');

        $descriptorId = $this->createReadyDescriptor(self::FACILITY_ID, format: 'csv');
        // Force the snapshot upper bound to the first recorded event.
        DB::table('audit_export_jobs')->where('id', $descriptorId)->update([
            'snapshot_recorded_at' => '2026-07-27 00:30:00.000',
        ]);

        $streamed = $this->getStreamed(
            str_replace('{exportId}', $descriptorId, AuditApi::ROUTE_DOWNLOAD_EXPORT),
            $this->headers(),
        );

        $this->assertSame(200, $streamed['status']);
        $lines = array_filter(explode("\r\n", $streamed['body']));
        $this->assertCount(2, $lines, 'header + exactly one data line');
        $this->assertStringContainsString('018f6f7d-0c00-7000-8000-000000000661', $lines[1]);
        $this->assertStringNotContainsString('018f6f7d-0c00-7000-8000-000000000662', $lines[1]);
    }

    public function test_stream_projection_failure_records_one_failed_download_attempt(): void
    {
        $eventId = '018f6f7d-0c00-7000-8000-000000000663';
        $this->insertEvent(
            $eventId,
            1,
            '2026-07-27 00:30:00.000',
            occurredAt: 'not-a-timestamp',
        );
        $descriptorId = $this->createReadyDescriptor(self::FACILITY_ID, format: 'ndjson');

        $bufferLevel = ob_get_level();
        try {
            $this->getStreamed(
                str_replace('{exportId}', $descriptorId, AuditApi::ROUTE_DOWNLOAD_EXPORT),
                $this->headers(),
            );
            $this->fail('Invalid export projection must fail the stream.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('audit_export_timestamp_invalid', $exception->getMessage());
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
        }

        $activity = DB::table('audit_events')
            ->where('action', 'audit.export.downloaded')
            ->where('subject_id', $descriptorId)
            ->first();
        $this->assertNotNull($activity);
        $this->assertSame('failed', $activity->outcome);
        $context = json_decode((string) $activity->context, true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame('failed', $context['attempt_outcome'] ?? null);
    }

    public function test_download_returns_410_for_expired_descriptor_and_records_attempt_activity(): void
    {
        $descriptorId = $this->createReadyDescriptor(self::FACILITY_ID);
        DB::table('audit_export_jobs')->where('id', $descriptorId)->update([
            'expires_at' => '2026-07-26 12:00:00.000',
        ]);

        $resp = $this->getJson(str_replace('{exportId}', $descriptorId, AuditApi::ROUTE_DOWNLOAD_EXPORT), $this->headers());
        $resp->assertStatus(410)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/audit-export-expired')
            ->assertJsonPath('detail', 'The audit export has expired and can no longer be downloaded.');

        // Exactly one failed-attempt activity, never a completion event.
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'audit.export.downloaded')
            ->where('subject_id', $descriptorId)
            ->count());
        $this->assertSame(0, DB::table('outbox_events')
            ->where('event_type', AuditExportCompletedV1::EVENT_TYPE)
            ->where('aggregate_id', $descriptorId)
            ->count());
    }

    public function test_malformed_export_id_returns_concealed_404_and_records_attempt_with_null_subject_id(): void
    {
        $malformedId = 'not-a-uuid';
        $this->getJson(str_replace('{exportId}', $malformedId, AuditApi::ROUTE_DOWNLOAD_EXPORT), $this->headers())
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/audit-export-not-found');

        // Exactly one attempt record with subjectId = null and only
        // a redacted attempt_export_id_invalid flag in the context.
        $activity = DB::table('audit_events')
            ->where('action', 'audit.export.downloaded')
            ->whereNull('subject_id')
            ->where('subject_type', 'audit_export')
            ->get();
        $this->assertCount(1, $activity);
        $context = json_decode((string) $activity[0]->context, true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame('not_found', $context['attempt_outcome']);
        $this->assertTrue($context['attempt_export_id_invalid']);
        $this->assertSame('invalid', $context['attempt_export_id_reason']);

        // The raw malformed id never appears in the persisted context.
        $this->assertStringNotContainsString($malformedId, (string) $activity[0]->context);

        // Missing-export 404 has identical bytes.
        $missing = $this->getJson(
            str_replace('{exportId}', '018f6f7d-0c00-7000-8000-000000000999', AuditApi::ROUTE_DOWNLOAD_EXPORT),
            $this->headers(),
        )->assertNotFound();
        $malformedResp = $this->getJson(
            str_replace('{exportId}', $malformedId, AuditApi::ROUTE_DOWNLOAD_EXPORT),
            $this->headers(idempotencyKey: 'malformed-replay'),
        )->assertNotFound();
        $this->assertSame($missing->getContent(), $malformedResp->getContent());
    }

    public function test_capability_decision_runs_before_expiry_disclosure(): void
    {
        $descriptorId = $this->createReadyDescriptor(self::FACILITY_ID);
        // Force the descriptor into a "ready but expired by clock" state
        // without ever calling markExpired; the controller must CAS to
        // expired and still conceal as 404 when capability is denied.
        DB::table('audit_export_jobs')->where('id', $descriptorId)->update([
            'expires_at' => '2026-07-26 12:00:00.000',
            'lock_version' => 1,
        ]);
        $this->decisions->allowExport = false;

        $this->getJson(str_replace('{exportId}', $descriptorId, AuditApi::ROUTE_DOWNLOAD_EXPORT), $this->headers())
            ->assertNotFound()
            ->assertJsonPath('type', 'https://cluster.example/problems/audit-export-not-found');

        // Capability denial observed exactly once, BEFORE expiry state was
        // disclosed to the caller.
        $this->assertCount(1, $this->decisions->calls);
        $this->assertSame('audit.event.export', $this->decisions->calls[0]['capability']);

        // The 410 attempt outcome must NOT be recorded when capability
        // is denied — denied remains an attempt_outcome of `forbidden`
        // so the audit trail cannot leak the descriptor's existence.
        $attempt = DB::table('audit_events')
            ->where('action', 'audit.export.downloaded')
            ->where('subject_id', $descriptorId)
            ->first();
        $this->assertNotNull($attempt);
        $context = json_decode((string) $attempt->context, true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame('forbidden', $context['attempt_outcome']);
    }

    public function test_first_observation_cas_advances_status_and_lock_version_and_bumps_etag(): void
    {
        $descriptorId = $this->createReadyDescriptor(self::FACILITY_ID);
        DB::table('audit_export_jobs')->where('id', $descriptorId)->update([
            'expires_at' => '2026-07-26 12:00:00.000',
            'lock_version' => 1,
        ]);

        // First GET: descriptor transitions ready→expired, lock_version
        // increments to 2, ETag carries the bumped lock_version.
        $first = $this->getJson(str_replace('{exportId}', $descriptorId, AuditApi::ROUTE_GET_EXPORT), $this->headers())
            ->assertOk();
        $row = DB::table('audit_export_jobs')->where('id', $descriptorId)->first();
        $this->assertSame('expired', $row->status);
        $this->assertSame(2, (int) $row->lock_version);
        $expectedEtag = '"'.$descriptorId.':2"';
        $this->assertSame($expectedEtag, $first->headers->get('ETag'));
        $first->assertJsonPath('data.status', 'expired');

        // Second GET sees the same bumped state with the same ETag.
        $second = $this->getJson(str_replace('{exportId}', $descriptorId, AuditApi::ROUTE_GET_EXPORT), $this->headers())
            ->assertOk();
        $this->assertSame($expectedEtag, $second->headers->get('ETag'));
        $row = DB::table('audit_export_jobs')->where('id', $descriptorId)->first();
        $this->assertSame(2, (int) $row->lock_version, 'Lock version must not advance again.');
    }

    public function test_cas_predicate_protects_against_concurrent_advance_with_stale_lock_version(): void
    {
        $descriptorId = $this->createReadyDescriptor(self::FACILITY_ID);
        DB::table('audit_export_jobs')->where('id', $descriptorId)->update([
            'expires_at' => '2026-07-26 12:00:00.000',
            'lock_version' => 5,
        ]);

        // Race two CAS attempts with the same expected lock_version:
        // exactly one must succeed.
        $repo = $this->app->make(AuditExportRepository::class);
        $first = $repo->markExpired($descriptorId, 5);
        $second = $repo->markExpired($descriptorId, 5);
        $this->assertTrue($first, 'First CAS must succeed.');
        $this->assertFalse($second, 'Second CAS with stale lock_version must not advance.');
        $row = DB::table('audit_export_jobs')->where('id', $descriptorId)->first();
        $this->assertSame('expired', $row->status);
        $this->assertSame(6, (int) $row->lock_version);
    }

    public function test_mb_strlen_reason_unicode_characters_are_not_over_bounded(): void
    {
        // 500 Unicode characters (1500 bytes) must be accepted; 501 must
        // be rejected. mb_strlen counts UTF-8 characters, not bytes.
        $accept = str_repeat('ن', 500); // Arabic "n" — 2 bytes each, 1000 bytes
        $reject = str_repeat('ن', 501);

        $this->postJson(AuditApi::ROUTE_CREATE_EXPORT, [
            'format' => 'csv',
            'filters' => [],
            'reason' => $accept,
        ], $this->headers(idempotencyKey: 'unicode-accept'))
            ->assertCreated();

        $this->postJson(AuditApi::ROUTE_CREATE_EXPORT, [
            'format' => 'csv',
            'filters' => [],
            'reason' => $reject,
        ], $this->headers(idempotencyKey: 'unicode-reject'))
            ->assertStatus(422)
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-export-reason')
            ->assertJsonMissingPath('reason');
    }

    public function test_csv_cells_with_tab_or_carriage_return_first_byte_are_quote_prefixed(): void
    {
        $projection = new AuditExportProjection($this->app->make(SensitiveValueRedactor::class));
        $row = array_fill_keys(AuditExportSection8Columns::COLUMNS, '');
        $row['context'] = "\tcmd";
        $this->assertStringContainsString("'\tcmd", $projection->toCsvLine($row));
        $row['context'] = "\rdata";
        $this->assertStringContainsString("'\rdata", $projection->toCsvLine($row));
        // Pure control character also gets quoted.
        $row['context'] = "\nplain";
        $this->assertStringContainsString("\nplain", $projection->toCsvLine($row));
        $this->assertStringContainsString('"', $projection->toCsvLine($row));
    }

    public function test_chunked_iteration_is_hard_bounded_for_both_count_and_download(): void
    {
        // Insert one event under the bound; confirm chunked count and
        // stream both succeed.
        $eventId = '018f6f7d-0c00-7000-8000-000000000671';
        $this->insertEvent($eventId, 1, '2026-07-27 00:30:00.000');

        $created = $this->postJson(AuditApi::ROUTE_CREATE_EXPORT, [
            'format' => 'ndjson',
            'filters' => [],
            'reason' => 'bounded chunked export',
        ], $this->headers(idempotencyKey: 'chunked-export'))->assertCreated();

        $streamed = $this->getStreamed(
            str_replace('{exportId}', (string) $created->json('data.id'), AuditApi::ROUTE_DOWNLOAD_EXPORT),
            $this->headers(),
        );
        $this->assertSame(200, $streamed['status']);
        $this->assertStringContainsString($eventId, $streamed['body']);
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
        string $idempotencyKey = 'audit-export-key',
    ): array {
        return [
            'X-Correlation-ID' => $correlationId,
            'X-Test-Audit-Authenticated' => $authenticated ? '1' : '0',
            'X-Test-Audit-User' => $userId,
            'X-Test-Audit-Facility' => $facilityId,
            'X-Test-Audit-Organization-Units' => implode(',', $organizationUnitIds),
            'Idempotency-Key' => $idempotencyKey,
            'X-CSRF-Token' => 'good',
        ];
    }

    private function insertEvent(
        string $id,
        int $sequence,
        string $recordedAt,
        array $context = [],
        string $occurredAt = '2026-07-27 00:00:00.000',
    ): void {
        DB::table('audit_events')->insert([
            'id' => $id,
            'request_hash' => hash('sha256', 'export-request-'.$id),
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
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
            'retention_until' => '2033-07-27 12:00:00.000',
            'previous_hash' => $sequence === 1 ? null : hash('sha256', 'export-event-'.($sequence - 1)),
            'event_hash' => hash('sha256', 'export-event-'.$sequence),
            'integrity_key_version' => 'v1',
        ]);
    }

    private function createReadyDescriptor(string $facilityId, string $format = 'csv'): string
    {
        $id = (string) Str::uuid7();
        DB::table('audit_export_jobs')->insert([
            'id' => $id,
            'principal_id' => self::USER_ID,
            'facility_id' => $facilityId,
            'query' => json_encode([], JSON_THROW_ON_ERROR),
            'query_hash' => str_repeat('0', 64),
            'reason_redacted' => '[REDACTED]',
            'format' => $format,
            'snapshot_recorded_at' => '2026-07-27 00:35:00.000',
            'status' => 'ready',
            'event_count' => 1,
            'lock_version' => 1,
            'expires_at' => '2033-07-27 12:00:00.000',
            'created_at' => '2026-07-27 00:30:00.000',
            'updated_at' => '2026-07-27 00:30:00.000',
        ]);

        return $id;
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function getStreamed(string $url, array $headers): array
    {
        /** @var \Illuminate\Testing\TestResponse $response */
        $response = $this->get($url, $headers);
        $content = (string) $response->streamedContent();
        $normalized = [];

        foreach (['Content-Type', 'Cache-Control', 'Content-Disposition'] as $name) {
            $normalized[$name] = (string) $response->headers->get($name, '');
        }

        return [
            'status' => $response->getStatusCode(),
            'headers' => $normalized,
            'body' => $content,
        ];
    }
}

final class AuditExportPrincipalResolver implements ResolvePrincipalContext
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
        $units = array_filter(explode(',', (string) $request->header(
            'X-Test-Audit-Organization-Units',
            $this->defaultOrganizationUnitId,
        )));

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

final class AuditExportDecisionEngine implements DecideAccess
{
    public bool $allowExport = true;

    /** @var list<string> */
    public array $deniedRecordIds = [];

    /** @var array<string, 'hidden'|'masked'|'readonly'|'editable'> */
    public array $fieldAccess = [];

    /** @var list<array{actor: array<string, mixed>, capability: string, facts: RecordFacts}> */
    public array $calls = [];

    public function __construct(private readonly string $decisionId) {}

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        assert($facts instanceof RecordFacts);
        $this->calls[] = compact('actor', 'capability', 'facts');
        $allowed = $capability !== 'audit.event.export'
            ? true
            : ($this->allowExport
                ? ! in_array((string) $facts->recordId, $this->deniedRecordIds, true)
                : false);

        return new AccessDecision(
            decision: $allowed ? 'allow' : 'deny',
            action: $capability,
            resourceType: $facts->resourceType,
            reasonCodes: [$allowed ? 'audit_export_allowed' : 'audit_export_denied'],
            policyVersion: 'audit-export-test-v1',
            factsVersion: 'audit-export-test-v1',
            classification: $facts->classification,
            decisionId: $allowed ? $this->decisionId : null,
            allowedActions: $allowed ? ['audit.event.export'] : [],
            fieldAccess: $allowed ? $this->fieldAccess : [],
        );
    }
}
