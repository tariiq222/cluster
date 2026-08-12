<?php

declare(strict_types=1);

namespace Modules\Documents\Tests;

use DateTimeImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Documents\Application\AuthorizedDocumentActor;
use Modules\Documents\Application\CompleteDocumentUpload;
use Modules\Documents\Application\DocumentDownloadGrant;
use Modules\Documents\Application\DocumentDownloadService;
use Modules\Documents\Application\DocumentMetadata;
use Modules\Documents\Application\DocumentMutationHandler;
use Modules\Documents\Application\IdempotencyContext;
use Modules\Documents\Application\InitiatedDocumentUpload;
use Modules\Documents\Application\InitiateDocumentUpload;
use Modules\Documents\Application\StoredObjectProperties;
use Modules\Documents\Application\UploadFileMetadata;
use Modules\Documents\Contracts\DocumentDownloadGrantIssuer;
use Modules\Documents\Contracts\LinkedResourceAuthorizationFacts;
use Modules\Documents\Domain\DocumentRetentionPolicy;
use Modules\Documents\Domain\DocumentUploadPolicy;
use Modules\Documents\Features\DocumentDownload\Http\DownloadDocumentController;
use Modules\Documents\Features\DocumentLifecycle\Http\CreateDocumentController;
use Modules\Documents\Features\DocumentLifecycle\Http\GetDocumentController;
use Modules\Documents\Features\DocumentLifecycle\Http\TransitionDocumentController;
use Modules\Documents\Features\Retention\ExpireExpiredDocuments;
use Modules\Documents\Features\Upload\DocumentUploadHandler;
use Modules\Documents\Tests\Support\InMemoryMalwareScanner;
use Modules\Documents\Tests\Support\InMemoryPrivateObjectStorage;
use Modules\Documents\Tests\Support\InMemoryTrustedDocumentAuthorizationContext;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Shared\Contracts\TransactionalOutbox;
use Tests\TestCase;

/**
 * Retention read/write correctness, expiry enforcement, archive/hold state
 * machine guards, unarchive, promotion non-resurrection, and download
 * retention checks for the Documents module.
 */
final class DocumentRetentionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000899';

    public const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000801';

    public const PRINCIPAL_ID = '018f6f7d-0c00-7000-8000-000000000802';

    private const DOCUMENT_PUBLIC_ID = '018f6f7d-0c00-7000-8000-000000000811';

    private const DOCUMENT_ID = '018f6f7d-0c00-7000-8000-000000000812';

    private DecideAccess $access;

    protected function setUp(): void
    {
        parent::setUp();
        $this->access = new class implements DecideAccess
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
                return new AccessDecision('allow', $capability, $facts->resourceType, [], 'test-policy-v1', 'test-facts-v1', $facts->classification);
            }
        };
    }

    public function test_metadata_create_serializes_restriction_and_retention_without_conflation(): void
    {
        $controller = new CreateDocumentController($this->principals(), $this->access, $this->app->make(DocumentMutationHandler::class), $this->app->make(\Modules\Documents\Application\DocumentAuthorizationRecordFactsBuilder::class));
        $response = ($controller)($this->jsonRequest('POST', [
            'title' => 'Governed record',
            'description' => 'Metadata-only document',
            'classification' => 'confidential',
            'owner_organization_unit_id' => self::FACILITY_ID,
            'restriction_policy_key' => 'restriction_v1',
        ], 'create-conflation'));
        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        $row = DB::table('documents')->where('public_id', $response->getData(true)['data']['id'])->first();
        $this->assertSame('restriction_v1', $row->restriction_policy_key);
        $this->assertNull($row->retention_policy_key);
        $this->assertNull($row->retention_until);

        $data = $response->getData(true)['data'];
        $this->assertSame('restriction_v1', $data['restriction_policy_key']);
        $this->assertNull($data['retention_policy_key']);
        $this->assertNull($data['retention_until']);
    }

    public function test_upload_initiate_sets_retention_and_leaves_restriction_unset(): void
    {
        $storage = new InMemoryPrivateObjectStorage;
        $handler = $this->handler($storage, new InMemoryMalwareScanner);
        $started = $this->initiate($handler, 'retention-upload', 'internal');

        $row = DB::table('documents')->where('public_id', $started->documentId)->first();
        $this->assertNotNull($row->retention_until);
        $this->assertSame('administrative_7_years', $row->retention_policy_key);
        $this->assertNull($row->restriction_policy_key);
    }

    public function test_get_serializes_retention_fields_and_advertises_coherent_actions(): void
    {
        $documentId = $this->seedDocument(['status' => 'active', 'legal_hold' => false, 'retention_until' => '2031-01-01 00:00:00.000000', 'retention_policy_key' => 'administrative_7_years', 'restriction_policy_key' => 'restriction_v1']);
        $controller = new GetDocumentController($this->principals(), $this->access, $this->app->make(\Modules\Documents\Application\DocumentAuthorizationRecordFactsBuilder::class));
        $response = ($controller)($this->jsonRequest('GET'), $documentId);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $response->getData(true)['data'];
        $this->assertSame('restriction_v1', $data['restriction_policy_key']);
        $this->assertSame('administrative_7_years', $data['retention_policy_key']);
        $this->assertSame('2031-01-01T00:00:00.000Z', $data['retention_until']);
        $this->assertContains('archive', $data['allowed_actions']);
        $this->assertContains('place-hold', $data['allowed_actions']);
        $this->assertNotContains('unarchive', $data['allowed_actions']);
    }

    public function test_archive_is_refused_while_a_legal_hold_is_active(): void
    {
        $documentId = $this->seedDocument(['status' => 'held', 'legal_hold' => true, 'legal_hold_reason' => 'litigation']);
        $controller = new TransitionDocumentController($this->principals(), $this->access, $this->app->make(DocumentMutationHandler::class), $this->app->make(\Modules\Documents\Application\DocumentAuthorizationRecordFactsBuilder::class));

        $response = ($controller)($this->jsonRequest('POST', ['reason' => 'close it'], 'archive-held', self::CORRELATION_ID, '1'), $documentId, 'archive');
        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/document-legal-hold-active', $response->getData(true)['type']);
        $this->assertSame('held', DB::table('documents')->where('public_id', $documentId)->value('status'));
    }

    public function test_held_document_does_not_advertise_archive_and_can_release_hold(): void
    {
        $documentId = $this->seedDocument(['status' => 'held', 'legal_hold' => true, 'legal_hold_reason' => 'litigation']);
        $controller = new GetDocumentController($this->principals(), $this->access, $this->app->make(\Modules\Documents\Application\DocumentAuthorizationRecordFactsBuilder::class));
        $data = (($controller)($this->jsonRequest('GET'), $documentId))->getData(true)['data'];

        $this->assertNotContains('archive', $data['allowed_actions']);
        $this->assertContains('release-hold', $data['allowed_actions']);

        $transition = new TransitionDocumentController($this->principals(), $this->access, $this->app->make(DocumentMutationHandler::class), $this->app->make(\Modules\Documents\Application\DocumentAuthorizationRecordFactsBuilder::class));
        $released = ($transition)($this->jsonRequest('POST', ['reason' => 'matter closed'], 'release-held', self::CORRELATION_ID, '1'), $documentId, 'release-hold');
        $this->assertSame(Response::HTTP_OK, $released->getStatusCode());
        $this->assertFalse($released->getData(true)['data']['legal_hold']);
    }

    public function test_hold_actions_are_refused_on_archived_documents_and_unarchive_restores(): void
    {
        $documentId = $this->seedDocument(['status' => 'archived']);
        $controller = new TransitionDocumentController($this->principals(), $this->access, $this->app->make(DocumentMutationHandler::class), $this->app->make(\Modules\Documents\Application\DocumentAuthorizationRecordFactsBuilder::class));

        $placeHold = ($controller)($this->jsonRequest('POST', ['reason' => 'late hold'], 'place-on-archived', self::CORRELATION_ID, '1'), $documentId, 'place-hold');
        $this->assertSame(Response::HTTP_CONFLICT, $placeHold->getStatusCode());

        $unarchive = ($controller)($this->jsonRequest('POST', ['reason' => 'reopen'], 'unarchive-a', self::CORRELATION_ID, '1'), $documentId, 'unarchive');
        $this->assertSame(Response::HTTP_OK, $unarchive->getStatusCode());
        $this->assertSame('draft', $unarchive->getData(true)['data']['status'], 'A version-less document must not be resurrected to active.');

        $replayed = ($controller)($this->jsonRequest('POST', ['reason' => 'reopen'], 'unarchive-a', self::CORRELATION_ID, '2'), $documentId, 'unarchive');
        $this->assertSame($unarchive->getData(true), $replayed->getData(true));

        $again = ($controller)($this->jsonRequest('POST', ['reason' => 'reopen again'], 'unarchive-b', self::CORRELATION_ID, '2'), $documentId, 'unarchive');
        $this->assertSame(Response::HTTP_CONFLICT, $again->getStatusCode(), 'Unarchive must be refused on a non-archived document.');
    }

    public function test_unarchive_restores_active_only_when_a_current_version_exists(): void
    {
        $documentId = $this->seedDocument(['status' => 'archived']);
        $this->seedVersion($documentId);
        $controller = new TransitionDocumentController($this->principals(), $this->access, $this->app->make(DocumentMutationHandler::class), $this->app->make(\Modules\Documents\Application\DocumentAuthorizationRecordFactsBuilder::class));

        $unarchive = ($controller)($this->jsonRequest('POST', ['reason' => 'reopen'], 'unarchive-versioned', self::CORRELATION_ID, '1'), $documentId, 'unarchive');
        $this->assertSame(Response::HTTP_OK, $unarchive->getStatusCode());
        $this->assertSame('active', $unarchive->getData(true)['data']['status']);
        $this->assertSame('active', DB::table('documents')->where('public_id', $documentId)->value('status'));
    }

    public function test_unarchived_version_less_expired_document_is_not_immediately_re_archived(): void
    {
        $documentId = $this->seedDocument([
            'status' => 'archived',
            'retention_until' => '2020-01-01 00:00:00.000000',
            'retention_policy_key' => 'administrative_7_years',
        ]);
        $controller = new TransitionDocumentController($this->principals(), $this->access, $this->app->make(DocumentMutationHandler::class), $this->app->make(\Modules\Documents\Application\DocumentAuthorizationRecordFactsBuilder::class));
        $unarchive = ($controller)($this->jsonRequest('POST', ['reason' => 'reopen'], 'unarchive-expired', self::CORRELATION_ID, '1'), $documentId, 'unarchive');
        $this->assertSame(Response::HTTP_OK, $unarchive->getStatusCode());
        $this->assertSame('draft', $unarchive->getData(true)['data']['status']);

        $expiredCount = (new ExpireExpiredDocuments($this->app->make(TransactionalOutbox::class)))->expireOnce(100);
        $this->assertSame(0, $expiredCount, 'The expiry cycle must skip documents without a current version.');
        $this->assertSame('draft', DB::table('documents')->where('public_id', $documentId)->value('status'));
    }

    public function test_archived_document_advertises_unarchive_and_archive_is_idempotent_conflict(): void
    {
        $documentId = $this->seedDocument(['status' => 'archived']);
        $controller = new GetDocumentController($this->principals(), $this->access, $this->app->make(\Modules\Documents\Application\DocumentAuthorizationRecordFactsBuilder::class));
        $data = (($controller)($this->jsonRequest('GET'), $documentId))->getData(true)['data'];

        $this->assertContains('unarchive', $data['allowed_actions']);
        $this->assertNotContains('archive', $data['allowed_actions']);
        $this->assertNotContains('place-hold', $data['allowed_actions']);

        $transition = new TransitionDocumentController($this->principals(), $this->access, $this->app->make(DocumentMutationHandler::class), $this->app->make(\Modules\Documents\Application\DocumentAuthorizationRecordFactsBuilder::class));
        $response = ($transition)($this->jsonRequest('POST', ['reason' => 'again'], 'archive-archived', self::CORRELATION_ID, '1'), $documentId, 'archive');
        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    public function test_expiry_command_archives_elapsed_documents_but_never_legal_held_archived_or_version_less_ones(): void
    {
        $past = '2020-01-01 00:00:00.000000';
        $future = '2040-01-01 00:00:00.000000';
        $expired = $this->seedDocument(['status' => 'active', 'retention_until' => $past, 'retention_policy_key' => 'administrative_7_years'], '018f6f7d-0c00-7000-8000-000000000841');
        $this->seedVersion($expired);
        $versionless = $this->seedDocument(['status' => 'active', 'retention_until' => $past, 'retention_policy_key' => 'administrative_7_years'], '018f6f7d-0c00-7000-8000-000000000845');
        $held = $this->seedDocument(['status' => 'held', 'legal_hold' => true, 'legal_hold_reason' => 'litigation', 'retention_until' => $past], '018f6f7d-0c00-7000-8000-000000000842');
        $alreadyArchived = $this->seedDocument(['status' => 'archived', 'retention_until' => $past], '018f6f7d-0c00-7000-8000-000000000843');
        $notYet = $this->seedDocument(['status' => 'active', 'retention_until' => $future], '018f6f7d-0c00-7000-8000-000000000844');

        $expiredCount = (new ExpireExpiredDocuments($this->app->make(TransactionalOutbox::class)))->expireOnce(100);

        $this->assertSame(1, $expiredCount);
        $this->assertSame('archived', DB::table('documents')->where('public_id', $expired)->value('status'));
        $this->assertSame('active', DB::table('documents')->where('public_id', $versionless)->value('status'), 'Documents without a current version must be skipped by expiry.');
        $this->assertSame('held', DB::table('documents')->where('public_id', $held)->value('status'));
        $this->assertSame('archived', DB::table('documents')->where('public_id', $alreadyArchived)->value('status'));
        $this->assertSame('active', DB::table('documents')->where('public_id', $notYet)->value('status'));
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'com.cluster.documents.lifecycletransitioned.v1')->count());
    }

    public function test_expiry_command_requires_bounded_once_mode(): void
    {
        $this->artisan('documents:expire-retention', ['--limit' => 10])->assertFailed();
        $this->artisan('documents:expire-retention', ['--once' => true, '--limit' => 10])->assertSuccessful();
    }

    public function test_reconcile_promotion_refuses_to_resurrect_an_archived_document(): void
    {
        $storage = new InMemoryPrivateObjectStorage;
        $handler = $this->handler($storage, new InMemoryMalwareScanner);
        $started = $this->initiate($handler, 'archived-promotion', 'internal', 'archived-promotion');
        $properties = new StoredObjectProperties($this->hashFor('archived-promotion'), 512, 'application/pdf', 'etag-promotion', 'generation-promotion');
        $storage->completeUpload($started->uploadIntent->id, $properties);
        $handler->complete(
            $this->actor(DocumentUploadHandler::COMPLETE_OPERATION),
            $started->uploadIntent->id,
            new CompleteDocumentUpload($properties->sha256, $properties->sizeBytes),
            $this->idempotency(DocumentUploadHandler::COMPLETE_OPERATION, 'archived-promotion-complete', 'archived-promotion-complete'),
        );
        $handler->scanVersion(
            $this->actor(DocumentUploadHandler::SCAN_OPERATION),
            $started->versionId,
            $this->idempotency(DocumentUploadHandler::SCAN_OPERATION, 'archived-promotion-scan', 'archived-promotion-scan'),
        );
        DB::table('documents')->where('public_id', $started->documentId)->update([
            'status' => 'archived',
            'lock_version' => DB::raw('lock_version + 1'),
        ]);

        try {
            $handler->reconcilePromotion(
                $this->actor(DocumentUploadHandler::RECONCILE_PROMOTION_OPERATION),
                $started->versionId,
                $this->idempotency(DocumentUploadHandler::RECONCILE_PROMOTION_OPERATION, 'archived-promotion-reconcile', 'archived-promotion-reconcile'),
            );
            $this->fail('Promotion of an archived document must be refused.');
        } catch (DomainException $exception) {
            $this->assertSame('document_promotion_archived', $exception->getMessage());
        }
        $this->assertSame('archived', DB::table('documents')->where('public_id', $started->documentId)->value('status'));
        $this->assertSame(0, $storage->promotionCalls, 'No bytes may be promoted for an archived document.');
    }

    public function test_download_is_refused_for_elapsed_retention_unless_legal_held(): void
    {
        $this->seedDocument(['status' => 'active', 'legal_hold' => false, 'retention_until' => '2020-01-01 00:00:00.000000'], self::DOCUMENT_PUBLIC_ID);
        $this->seedVersion();

        $controller = new DownloadDocumentController($this->principals(), $this->downloadService());
        $expired = ($controller)($this->jsonRequest('GET'), self::DOCUMENT_PUBLIC_ID);
        $this->assertSame(Response::HTTP_CONFLICT, $expired->getStatusCode());
        $this->assertSame('https://cluster.example/problems/document-retention-expired', $expired->getData(true)['type']);

        DB::table('documents')->where('public_id', self::DOCUMENT_PUBLIC_ID)->update(['legal_hold' => true, 'status' => 'held', 'legal_hold_reason' => 'litigation']);
        $held = ($controller)($this->jsonRequest('GET'), self::DOCUMENT_PUBLIC_ID);
        $this->assertSame(302, $held->getStatusCode());
    }

    public function test_download_stays_available_while_retention_is_active(): void
    {
        $this->seedDocument(['status' => 'active', 'legal_hold' => false, 'retention_until' => '2040-01-01 00:00:00.000000'], self::DOCUMENT_PUBLIC_ID);
        $this->seedVersion();

        $controller = new DownloadDocumentController($this->principals(), $this->downloadService());
        $response = ($controller)($this->jsonRequest('GET'), self::DOCUMENT_PUBLIC_ID);
        $this->assertSame(302, $response->getStatusCode());
    }

    private function downloadService(): DocumentDownloadService
    {
        return new DocumentDownloadService(
            $this->access,
            new class implements LinkedResourceAuthorizationFacts
            {
                public function resolve(\Modules\Documents\Contracts\DocumentSourceReference $reference): RecordFacts
                {
                    return new RecordFacts('018f6f7d-0c00-7000-8000-000000000801', $reference->sourceType, 'confidential');
                }
            },
            $this->app->make(\Modules\Documents\Application\DocumentAuthorizationRecordFactsBuilder::class),
            new class implements DocumentDownloadGrantIssuer
            {
                public function issue(string $documentId, string $versionId, string $principalId): DocumentDownloadGrant
                {
                    return new DocumentDownloadGrant($documentId, $versionId, 'https://download.invalid/'.$versionId, new DateTimeImmutable('+5 minutes'), 'test-correlation');
                }
            },
            new \Modules\Documents\Infrastructure\Persistence\DatabaseSensitiveAccessEventRecorder(
                $this->app->make(\Modules\Authorization\Contracts\RecordSensitiveAccessEvent::class),
            ),
        );
    }

    private function handler(InMemoryPrivateObjectStorage $storage, InMemoryMalwareScanner $scanner): DocumentUploadHandler
    {
        return new DocumentUploadHandler($storage, $scanner, DocumentUploadPolicy::fromConfig(config('documents')), DocumentRetentionPolicy::fromConfig(config('documents')), $this->app->make(TransactionalOutbox::class));
    }

    private function initiate(DocumentUploadHandler $handler, string $key, string $classification = 'internal', ?string $idempotencyKey = null): InitiatedDocumentUpload
    {
        return $handler->initiate(
            $this->actor(DocumentUploadHandler::INITIATE_OPERATION),
            new InitiateDocumentUpload('document_version', new DocumentMetadata('اختبار الاحتفاظ', null, $classification), new UploadFileMetadata($key.'.pdf', 512, 'application/pdf', $this->hashFor($key))),
            $this->idempotency(DocumentUploadHandler::INITIATE_OPERATION, $idempotencyKey ?? $key.'-initiate', $key.'-initiate'),
        );
    }

    private function actor(string $operation): AuthorizedDocumentActor
    {
        return AuthorizedDocumentActor::fromTrustedContext(new InMemoryTrustedDocumentAuthorizationContext(self::PRINCIPAL_ID, self::FACILITY_ID, '018f6f7d-0c00-7000-8000-000000000999', [$operation]), $operation);
    }

    private function idempotency(string $operation, string $key, string $request): IdempotencyContext
    {
        return new IdempotencyContext(self::PRINCIPAL_ID, $operation, $key, hash('sha256', $request));
    }

    private function hashFor(string $value): string
    {
        return hash('sha256', $value);
    }

    /** @param array<string, mixed> $overrides */
    private function seedDocument(array $overrides = [], ?string $publicId = null): string
    {
        $publicId ??= self::DOCUMENT_PUBLIC_ID;
        $id = $publicId === self::DOCUMENT_PUBLIC_ID ? self::DOCUMENT_ID : \Illuminate\Support\Str::uuid7()->toString();
        $base = [
            'id' => $id,
            'public_id' => $publicId,
            'owner_organization_unit_id' => self::FACILITY_ID,
            'created_by_user_id' => self::PRINCIPAL_ID,
            'name' => 'Governed record',
            'description' => null,
            'classification' => 'internal',
            'status' => 'active',
            'current_version_id' => null,
            'retention_until' => null,
            'retention_policy_key' => null,
            'restriction_policy_key' => 'restriction_v1',
            'legal_hold' => false,
            'legal_hold_reason' => null,
            'legal_hold_at' => null,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $now = now();
        DB::table('documents')->insert([...$base, ...$overrides, 'created_at' => $now, 'updated_at' => $now]);

        return $publicId;
    }

    private function seedVersion(?string $documentPublicId = self::DOCUMENT_PUBLIC_ID): void
    {
        $documentId = (string) DB::table('documents')->where('public_id', $documentPublicId)->value('id');
        $storageObjectId = \Illuminate\Support\Str::uuid7()->toString();
        $versionId = \Illuminate\Support\Str::uuid7()->toString();
        $versionPublicId = \Illuminate\Support\Str::uuid7()->toString();
        $now = now();
        DB::table('document_storage_objects')->insert([
            'id' => $storageObjectId,
            'disk' => 'available',
            'object_key' => $storageObjectId.'.blob',
            'etag' => 'etag-download',
            'generation' => 'generation-download',
            'storage_class' => 'available',
            'immutable' => true,
            'immutable_since' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('document_versions')->insert([
            'id' => $versionId,
            'public_id' => $versionPublicId,
            'document_id' => $documentId,
            'storage_object_id' => $storageObjectId,
            'version_number' => 1,
            'original_filename' => 'record.pdf',
            'declared_mime_type' => 'application/pdf',
            'detected_mime_type' => 'application/pdf',
            'size_bytes' => 512,
            'sha256' => $this->hashFor('download'),
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
        DB::table('documents')->where('id', $documentId)->update(['current_version_id' => $versionId]);
    }

    private function principals(): ResolveDevelopmentFixturePrincipal
    {
        return new class implements ResolveDevelopmentFixturePrincipal
        {
            public function issue(array $principal): array
            {
                return ['access_token' => 'test-token', 'expires_at' => '2026-07-19T16:00:00Z'];
            }

            public function resolve(Request $request): array
            {
                return ['user_id' => DocumentRetentionLifecycleTest::PRINCIPAL_ID, 'facility_id' => DocumentRetentionLifecycleTest::FACILITY_ID];
            }
        };
    }

    /** @param array<string, mixed> $payload */
    private function jsonRequest(string $method, array $payload = [], string $idempotencyKey = '', ?string $correlationId = self::CORRELATION_ID, ?string $ifMatch = null): Request
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($correlationId !== null) {
            $server['HTTP_X_CORRELATION_ID'] = $correlationId;
        }
        if ($idempotencyKey !== '') {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }
        if ($ifMatch !== null) {
            $server['HTTP_IF_MATCH'] = '"'.$ifMatch.'"';
        }

        return Request::create('/', $method, [], [], [], $server, json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
