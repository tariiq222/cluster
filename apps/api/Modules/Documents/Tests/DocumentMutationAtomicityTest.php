<?php

namespace Modules\Documents\Tests;

use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Contracts\AuditEventInput;
use Modules\Audit\Contracts\AuditEventReceipt;
use Modules\Audit\Contracts\RecordAuditEvent;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Documents\Application\DocumentDownloadGrant;
use Modules\Documents\Application\DocumentLinkService;
use Modules\Documents\Application\DocumentMutationHandler;
use Modules\Documents\Contracts\DocumentDownloadGrantIssuer;
use Modules\Documents\Contracts\DocumentSourceReference;
use Modules\Documents\Contracts\LinkedResourceAuthorizationFacts;
use Modules\Documents\Features\DocumentGrant\Http\CreateDocumentGrantController;
use Modules\Documents\Features\DocumentLifecycle\Http\CreateDocumentController;
use Modules\Documents\Features\DocumentLifecycle\Http\TransitionDocumentController;
use Modules\Documents\Features\DocumentLifecycle\Http\UpdateDocumentController;
use Modules\Documents\Features\DocumentLink\Http\LinkDocumentController;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use RuntimeException;
use Shared\Contracts\TransactionalOutbox;
use Tests\TestCase;

final class DocumentMutationAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private const DOCUMENT_ID = '018f6f7d-0c00-7000-8000-000000000801';

    private const DOCUMENT_PUBLIC_ID = '018f6f7d-0c00-7000-8000-000000000802';

    private const VERSION_ID = '018f6f7d-0c00-7000-8000-000000000803';

    private const VERSION_PUBLIC_ID = '018f6f7d-0c00-7000-8000-000000000804';

    private const STORAGE_ID = '018f6f7d-0c00-7000-8000-000000000805';

    public const PRINCIPAL_ID = '018f6f7d-0c00-7000-8000-000000000806';

    public const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000807';

    private const SOURCE_ID = '018f6f7d-0c00-7000-8000-000000000808';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000809';

    protected function setUp(): void
    {
        parent::setUp();
        $now = now();
        DB::table('documents')->insert([
            'id' => self::DOCUMENT_ID,
            'public_id' => self::DOCUMENT_PUBLIC_ID,
            'owner_organization_unit_id' => self::FACILITY_ID,
            'created_by_user_id' => self::PRINCIPAL_ID,
            'name' => 'Atomic document',
            'description' => null,
            'classification' => 'internal',
            'restriction_facts' => null,
            'status' => 'draft',
            'current_version_id' => self::VERSION_ID,
            'retention_until' => null,
            'retention_policy_key' => null,
            'legal_hold' => false,
            'legal_hold_reason' => null,
            'legal_hold_at' => null,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('document_storage_objects')->insert([
            'id' => self::STORAGE_ID,
            'disk' => 'documents-private',
            'object_key' => 'atomic.blob',
            'etag' => 'etag-atomic',
            'generation' => 'generation-atomic',
            'storage_class' => 'private',
            'immutable' => true,
            'immutable_since' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('document_versions')->insert([
            'id' => self::VERSION_ID,
            'public_id' => self::VERSION_PUBLIC_ID,
            'document_id' => self::DOCUMENT_ID,
            'storage_object_id' => self::STORAGE_ID,
            'version_number' => 1,
            'original_filename' => 'atomic.pdf',
            'declared_mime_type' => 'application/pdf',
            'detected_mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'sha256' => hash('sha256', 'atomic'),
            'scan_status' => 'clean',
            'availability_status' => 'available',
            'scan_engine_version' => 'test',
            'scan_result' => null,
            'scanned_at' => $now,
            'available_at' => $now,
            'promotion_requested_at' => null,
            'created_by_user_id' => self::PRINCIPAL_ID,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_create_rolls_back_document_idempotency_and_audit_when_outbox_append_fails(): void
    {
        $controller = new CreateDocumentController(
            $this->principals(),
            $this->access(),
            $this->mutations($this->failingOutbox()),
        );

        $this->assertOutboxFailure(fn () => $controller($this->request('POST', [
            'title' => 'Must roll back',
            'classification' => 'internal',
            'owner_organization_unit_id' => self::FACILITY_ID,
            'restriction_policy_key' => 'documents.default',
        ], ['HTTP_IDEMPOTENCY_KEY' => 'create-rollback'])));

        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('document_idempotency_keys', 0);
        $this->assertDatabaseCount('document_access_events', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_grant_effects_roll_back_when_outbox_append_fails(): void
    {
        $controller = new CreateDocumentGrantController(
            $this->principals(),
            $this->access(),
            new class implements DocumentDownloadGrantIssuer
            {
                public function issue(string $documentId, string $versionId, string $principalId): DocumentDownloadGrant
                {
                    return new DocumentDownloadGrant($documentId, $versionId, 'https://download.invalid/grant', new DateTimeImmutable('+5 minutes'), 'grant');
                }
            },
            $this->mutations($this->failingOutbox()),
        );

        $this->assertOutboxFailure(fn () => $controller(
            $this->request('POST', ['version_id' => self::VERSION_PUBLIC_ID], ['HTTP_IDEMPOTENCY_KEY' => 'grant-rollback']),
            self::DOCUMENT_PUBLIC_ID,
            'download',
        ));

        $this->assertDatabaseCount('document_idempotency_keys', 0);
        $this->assertDatabaseCount('document_access_events', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_grant_records_one_audit_call_with_exact_input_mapping(): void
    {
        $spy = $this->recordingAudit();
        $handler = $this->mutations($this->app->make(TransactionalOutbox::class), null, $spy);
        $document = (object) [
            'id' => self::DOCUMENT_ID,
            'public_id' => self::DOCUMENT_PUBLIC_ID,
            'classification' => 'confidential',
            'owner_organization_unit_id' => self::FACILITY_ID,
        ];
        $version = (object) ['id' => self::VERSION_ID, 'public_id' => self::VERSION_PUBLIC_ID];

        $handler->recordGrant(
            $document,
            $version,
            self::PRINCIPAL_ID,
            'download',
            'documents.download-grant',
            hash('sha256', 'grant-success'),
            hash('sha256', 'payload'),
            ['grant_type' => 'download'],
            self::CORRELATION_ID,
        );

        $this->assertCount(1, $spy->inputs);

        $input = $spy->inputs[0];
        $this->assertSame('documents', $input->sourceModule);
        $this->assertSame('documents.grant.issued', $input->action);
        $this->assertSame('com.cluster.documents.grantissued.v1', $input->eventType);
        $this->assertSame(AuditEventInput::ACTOR_USER, $input->actorType);
        $this->assertSame(self::PRINCIPAL_ID, $input->actorId);
        $this->assertNull($input->originalActorId);
        $this->assertSame('document', $input->subjectType);
        $this->assertSame(self::DOCUMENT_PUBLIC_ID, $input->subjectId);
        $this->assertSame(self::CORRELATION_ID, $input->correlationId);
        $this->assertSame(AuditEventInput::OUTCOME_SUCCEEDED, $input->outcome);
        $this->assertSame('confidential', $input->classification);
        $this->assertSame([
            'grant_type' => 'download',
            'version_id' => self::VERSION_PUBLIC_ID,
            'organization_unit_id' => self::FACILITY_ID,
        ], $input->context);
        $this->assertSame(AuditEventInput::RETENTION_REGULATED, $input->retentionClass);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $input->eventId);
        $this->assertSame('UTC', $input->occurredAt->getTimezone()->getName());
    }

    public function test_grant_equal_replay_does_not_invoke_record_grant_or_audit_again(): void
    {
        $spy = $this->recordingAudit();
        $controller = new CreateDocumentGrantController(
            $this->principals(),
            $this->access(),
            new class implements DocumentDownloadGrantIssuer
            {
                public function issue(string $documentId, string $versionId, string $principalId): DocumentDownloadGrant
                {
                    return new DocumentDownloadGrant($documentId, $versionId, 'https://download.invalid/replay', new DateTimeImmutable('+5 minutes'), 'grant');
                }
            },
            $this->mutations($this->app->make(TransactionalOutbox::class), null, $spy),
        );

        $first = $controller(
            $this->request('POST', ['version_id' => self::VERSION_PUBLIC_ID], ['HTTP_IDEMPOTENCY_KEY' => 'grant-replay']),
            self::DOCUMENT_PUBLIC_ID,
            'download',
        );
        $this->assertSame(201, $first->getStatusCode());
        $this->assertCount(1, $spy->inputs);

        $replayed = $controller(
            $this->request('POST', ['version_id' => self::VERSION_PUBLIC_ID], ['HTTP_IDEMPOTENCY_KEY' => 'grant-replay']),
            self::DOCUMENT_PUBLIC_ID,
            'download',
        );
        $this->assertSame(201, $replayed->getStatusCode());
        $this->assertSame($first->getData(true), $replayed->getData(true));
        $this->assertCount(1, $spy->inputs, 'Equal replay must not invoke the Audit recorder again.');
        $this->assertSame(1, DB::table('document_idempotency_keys')->count());
        $this->assertSame(1, DB::table('document_access_events')->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'com.cluster.documents.grantissued.v1')->count());
    }

    public function test_grant_audit_failure_rolls_back_every_producer_effect(): void
    {
        $controller = new CreateDocumentGrantController(
            $this->principals(),
            $this->access(),
            new class implements DocumentDownloadGrantIssuer
            {
                public function issue(string $documentId, string $versionId, string $principalId): DocumentDownloadGrant
                {
                    return new DocumentDownloadGrant($documentId, $versionId, 'https://download.invalid/audit-rollback', new DateTimeImmutable('+5 minutes'), 'grant');
                }
            },
            $this->mutations($this->app->make(TransactionalOutbox::class), null, $this->failingAudit()),
        );

        try {
            $controller(
                $this->request('POST', ['version_id' => self::VERSION_PUBLIC_ID], ['HTTP_IDEMPOTENCY_KEY' => 'grant-audit-rollback']),
                self::DOCUMENT_PUBLIC_ID,
                'download',
            );
            $this->fail('The injected audit failure must escape the command transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected audit failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('document_idempotency_keys', 0);
        $this->assertDatabaseCount('document_access_events', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_metadata_update_rolls_back_state_and_audit_when_outbox_append_fails(): void
    {
        $controller = new UpdateDocumentController($this->principals(), $this->access(), $this->mutations($this->failingOutbox()));

        $this->assertOutboxFailure(fn () => $controller(
            $this->request('PATCH', ['title' => 'Must roll back'], [
                'CONTENT_TYPE' => 'application/merge-patch+json',
                'HTTP_IF_MATCH' => '"1"',
            ]),
            self::DOCUMENT_PUBLIC_ID,
        ));

        $this->assertDatabaseHas('documents', ['id' => self::DOCUMENT_ID, 'name' => 'Atomic document', 'lock_version' => 1]);
        $this->assertDatabaseCount('document_access_events', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_link_rolls_back_link_lock_idempotency_and_audit_when_outbox_append_fails(): void
    {
        $facts = new class implements LinkedResourceAuthorizationFacts
        {
            public function resolve(DocumentSourceReference $reference): RecordFacts
            {
                return new RecordFacts(DocumentMutationAtomicityTest::FACILITY_ID, $reference->sourceType, 'internal');
            }
        };
        $controller = new LinkDocumentController(
            $this->principals(),
            $this->access(),
            $this->mutations($this->failingOutbox(), $facts),
        );

        $this->assertOutboxFailure(fn () => $controller(
            $this->request('POST', [
                'source' => ['source_module' => 'work_records', 'record_type' => 'work_record', 'record_id' => self::SOURCE_ID],
                'relation_type' => 'attachment',
            ], [
                'HTTP_IF_MATCH' => '"1"',
                'HTTP_IDEMPOTENCY_KEY' => 'link-rollback',
            ]),
            self::DOCUMENT_PUBLIC_ID,
        ));

        $this->assertDatabaseCount('document_links', 0);
        $this->assertDatabaseCount('document_idempotency_keys', 0);
        $this->assertDatabaseHas('documents', ['id' => self::DOCUMENT_ID, 'lock_version' => 1]);
        $this->assertDatabaseCount('document_access_events', 0);
    }

    public function test_transition_rolls_back_state_idempotency_and_audit_when_outbox_append_fails(): void
    {
        $controller = new TransitionDocumentController($this->principals(), $this->access(), $this->mutations($this->failingOutbox()));

        $this->assertOutboxFailure(fn () => $controller(
            $this->request('POST', ['reason' => 'Retention complete'], [
                'HTTP_IF_MATCH' => '"1"',
                'HTTP_IDEMPOTENCY_KEY' => 'archive-rollback',
            ]),
            self::DOCUMENT_PUBLIC_ID,
            'archive',
        ));

        $this->assertDatabaseHas('documents', ['id' => self::DOCUMENT_ID, 'status' => 'draft', 'lock_version' => 1]);
        $this->assertDatabaseCount('document_idempotency_keys', 0);
        $this->assertDatabaseCount('document_access_events', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    private function mutations(
        TransactionalOutbox $outbox,
        ?LinkedResourceAuthorizationFacts $facts = null,
        ?RecordAuditEvent $audit = null,
    ): DocumentMutationHandler {
        $facts ??= new class implements LinkedResourceAuthorizationFacts
        {
            public function resolve(DocumentSourceReference $reference): ?RecordFacts
            {
                return null;
            }
        };

        return new DocumentMutationHandler(
            new DocumentLinkService($this->access(), $facts),
            $outbox,
            $audit ?? $this->passingAudit(),
        );
    }

    private function principals(): ResolveDevelopmentFixturePrincipal
    {
        return new class implements ResolveDevelopmentFixturePrincipal
        {
            public function issue(array $principal): array
            {
                return ['access_token' => 'test', 'expires_at' => '2026-07-27T00:00:00Z'];
            }

            public function resolve(Request $request): array
            {
                return ['user_id' => DocumentMutationAtomicityTest::PRINCIPAL_ID, 'facility_id' => DocumentMutationAtomicityTest::FACILITY_ID];
            }
        };
    }

    private function access(): DecideAccess
    {
        return new class implements DecideAccess
        {
            public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                if ($facts === null) {
                    return new AccessDecision('allow', $capability, 'document', [], 'test-policy', 'test-facts', 'internal');
                }

                return new AccessDecision('allow', $capability, $facts->resourceType, [], 'test-policy', 'test-facts', $facts->classification);
            }
        };
    }

    private function failingOutbox(): TransactionalOutbox
    {
        return new class implements TransactionalOutbox
        {
            public function append(string $eventId, string $aggregateId, string $eventType, array $payload): void
            {
                throw new RuntimeException('injected outbox failure');
            }
        };
    }

    private function passingAudit(): RecordAuditEvent
    {
        return new class implements RecordAuditEvent
        {
            public function record(AuditEventInput $input): AuditEventReceipt
            {
                return new AuditEventReceipt(
                    eventId: $input->eventId,
                    streamKey: 'documents:document:'.$input->subjectId,
                    streamSequence: 1,
                    eventHash: str_repeat('a', 64),
                    recordedAt: $input->occurredAt,
                    replayed: false,
                );
            }
        };
    }

    private function failingAudit(): RecordAuditEvent
    {
        return new class implements RecordAuditEvent
        {
            public function record(AuditEventInput $input): AuditEventReceipt
            {
                throw new RuntimeException('injected audit failure');
            }
        };
    }

    private function recordingAudit(): DocumentMutationAuditRecorder
    {
        return new DocumentMutationAuditRecorder;
    }

    /** @param array<string, mixed> $payload @param array<string, string> $server */
    private function request(string $method, array $payload, array $server = []): Request
    {
        return Request::create('/', $method, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CORRELATION_ID' => self::CORRELATION_ID,
            ...$server,
        ], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function assertOutboxFailure(callable $mutation): void
    {
        try {
            $mutation();
            $this->fail('The injected outbox failure must escape the command transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected outbox failure', $exception->getMessage());
        }
    }
}

final class DocumentMutationAuditRecorder implements RecordAuditEvent
{
    /** @var list<AuditEventInput> */
    public array $inputs = [];

    public function record(AuditEventInput $input): AuditEventReceipt
    {
        $this->inputs[] = $input;

        return new AuditEventReceipt(
            eventId: $input->eventId,
            streamKey: 'documents:document:'.$input->subjectId,
            streamSequence: count($this->inputs),
            eventHash: str_repeat('b', 64),
            recordedAt: $input->occurredAt,
            replayed: false,
        );
    }
}
