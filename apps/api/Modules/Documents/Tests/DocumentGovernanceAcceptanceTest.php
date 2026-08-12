<?php

namespace Modules\Documents\Tests;

use DateTimeImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Contracts\RecordSensitiveAccessEvent;
use Modules\Documents\Application\AuthorizedDocumentActor;
use Modules\Documents\Application\CompleteDocumentUpload;
use Modules\Documents\Application\DocumentAccessRequest;
use Modules\Documents\Application\DocumentDownloadGrant;
use Modules\Documents\Application\DocumentDownloadService;
use Modules\Documents\Application\DocumentLinkService;
use Modules\Documents\Application\DocumentMetadata;
use Modules\Documents\Application\IdempotencyContext;
use Modules\Documents\Application\InitiateDocumentUpload;
use Modules\Documents\Application\StoredObjectProperties;
use Modules\Documents\Application\UploadFileMetadata;
use Modules\Documents\Contracts\DocumentDownloadGrantIssuer;
use Modules\Documents\Contracts\DocumentSourceReference;
use Modules\Documents\Contracts\LinkedResourceAuthorizationFacts;
use Modules\Documents\Domain\DocumentRetentionPolicy;
use Modules\Documents\Domain\DocumentUploadPolicy;
use Modules\Documents\Features\Upload\DocumentUploadHandler;
use Modules\Documents\Infrastructure\Persistence\DatabaseSensitiveAccessEventRecorder;
use Modules\Documents\Tests\Support\InMemoryMalwareScanner;
use Modules\Documents\Tests\Support\InMemoryPrivateObjectStorage;
use Modules\Documents\Tests\Support\InMemoryTrustedDocumentAuthorizationContext;
use Shared\Contracts\TransactionalOutbox;
use Tests\TestCase;

final class DocumentGovernanceAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_ID = '018f6f7d-0c00-7000-8000-000000000801';

    private const CREATOR_ID = '018f6f7d-0c00-7000-8000-000000000802';

    private const RECORD_ID = '018f6f7d-0c00-7000-8000-000000000901';

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('document_links')) {
            $migration = require base_path('Modules/Documents/Infrastructure/Persistence/Migrations/W18CreateDocumentGovernanceTables.php');
            $migration->up();
        }
        if (! Schema::hasTable('notification_dead_letters')) {
            // The Documents acceptance path only needs its own governance tables.
            // Notifications migration is loaded by Notifications tests/provider.
        }
    }

    public function test_clean_available_document_can_link_to_work_record_and_download(): void
    {
        [$started, $storage, $handler] = $this->availableDocument('internal', 'clean-available');
        $access = new AcceptanceDecideAccess;
        $facts = new AcceptanceLinkedFacts;
        $link = new DocumentLinkService($access, $facts, $this->app->make(\Modules\Documents\Application\DocumentAuthorizationRecordFactsBuilder::class));

        $linkId = $link->link(
            $started->documentId,
            new DocumentSourceReference('work_records', 'work_record', self::RECORD_ID),
            'attachment',
            self::CREATOR_ID,
            self::OWNER_ID,
            'work_record_constraint_v1',
        );
        $grant = $this->downloadService($access, $facts)->download(
            $started->documentId,
            $started->versionId,
            new DocumentAccessRequest(self::CREATOR_ID, self::OWNER_ID, '018f6f7d-0c00-7000-8000-000000000911'),
        );

        $this->assertSame($linkId, DB::table('document_links')->value('id'));
        $this->assertSame('work_record_constraint_v1', DB::table('document_links')->value('constraint_policy_key'));
        $this->assertSame($started->documentId, $grant->documentId);
        $this->assertSame('available', DB::table('document_versions')->where('public_id', $started->versionId)->value('availability_status'));
        $this->assertSame(1, $storage->promotionCalls);
        $this->assertSame(1, DB::table('document_access_events')->where('action', 'download')->count());
        unset($handler);
    }

    public function test_duplicate_link_returns_the_persisted_id_and_different_policy_conflicts(): void
    {
        [$started] = $this->availableDocument('internal', 'duplicate-link');
        $service = new DocumentLinkService(new AcceptanceDecideAccess, new AcceptanceLinkedFacts, $this->app->make(\Modules\Documents\Application\DocumentAuthorizationRecordFactsBuilder::class));
        $reference = new DocumentSourceReference('work_records', 'work_record', self::RECORD_ID);

        $first = $service->link($started->documentId, $reference, 'attachment', self::CREATOR_ID, self::OWNER_ID, 'policy-a');
        $second = $service->link($started->documentId, $reference, 'attachment', self::CREATOR_ID, self::OWNER_ID, 'policy-a');

        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('document_links')->where('document_id', DB::table('documents')->where('public_id', $started->documentId)->value('id'))->count());
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('document_link_conflict');
        $service->link($started->documentId, $reference, 'attachment', self::CREATOR_ID, self::OWNER_ID, 'policy-b');
    }

    public function test_quarantined_or_rejected_document_never_becomes_downloadable(): void
    {
        $storage = new InMemoryPrivateObjectStorage;
        $handler = $this->handler($storage, new InMemoryMalwareScanner);
        $started = $this->initiate($handler, 'quarantined.pdf', 'application/pdf', 'quarantined');
        $service = $this->downloadService(new AcceptanceDecideAccess, new AcceptanceLinkedFacts);

        try {
            $service->download(
                $started->documentId,
                $started->versionId,
                new DocumentAccessRequest(self::CREATOR_ID, self::OWNER_ID, '018f6f7d-0c00-7000-8000-000000000912'),
            );
            $this->fail('A quarantined document must not be downloadable.');
        } catch (DomainException $exception) {
            $this->assertSame('document_not_available', $exception->getMessage());
        }

        $infectedScanner = new InMemoryMalwareScanner;
        $infectedScanner->returnInfected();
        $infectedStorage = new InMemoryPrivateObjectStorage;
        $infectedHandler = $this->handler($infectedStorage, $infectedScanner);
        $rejected = $this->initiate($infectedHandler, 'rejected.pdf', 'application/pdf', 'rejected');
        $properties = new StoredObjectProperties($this->hashFor('rejected'), 512, 'application/pdf', 'etag-rejected', 'generation-rejected');
        $infectedStorage->completeUpload($rejected->uploadIntent->id, $properties);
        $infectedHandler->complete(
            $this->actor(DocumentUploadHandler::COMPLETE_OPERATION),
            $rejected->uploadIntent->id,
            new CompleteDocumentUpload($properties->sha256, $properties->sizeBytes),
            $this->idempotency(DocumentUploadHandler::COMPLETE_OPERATION, 'rejected-complete', 'rejected-complete'),
        );
        $scanned = $infectedHandler->scanVersion(
            $this->actor(DocumentUploadHandler::SCAN_OPERATION),
            $rejected->versionId,
            $this->idempotency(DocumentUploadHandler::SCAN_OPERATION, 'rejected-scan', 'rejected-scan'),
        );
        $this->assertSame('rejected', $scanned->availabilityStatus);
        try {
            $service->download(
                $rejected->documentId,
                $rejected->versionId,
                new DocumentAccessRequest(self::CREATOR_ID, self::OWNER_ID, '018f6f7d-0c00-7000-8000-000000000913'),
            );
            $this->fail('A rejected document must not be downloadable.');
        } catch (DomainException $exception) {
            $this->assertSame('document_not_available', $exception->getMessage());
        }
    }

    public function test_denied_linked_work_record_blocks_download_even_when_document_allows(): void
    {
        [$started] = $this->availableDocument('internal', 'linked-deny');
        $access = new AcceptanceDecideAccess(['work_record.read']);
        $facts = new AcceptanceLinkedFacts;
        DB::table('document_links')->insert([
            'id' => '018f6f7d-0c00-7000-0000-000000000921',
            'document_id' => DB::table('documents')->where('public_id', $started->documentId)->value('id'),
            'source_module' => 'work_records',
            'source_type' => 'work_record',
            'source_id' => self::RECORD_ID,
            'relation_type' => 'attachment',
            'link_classification' => 'confidential',
            'linked_by_user_id' => self::CREATOR_ID,
            'status' => 'active',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('linked_resource_access_denied');
        $this->downloadService($access, $facts)->download(
            $started->documentId,
            $started->versionId,
            new DocumentAccessRequest(self::CREATOR_ID, self::OWNER_ID, '018f6f7d-0c00-7000-8000-000000000922'),
        );
        $this->assertDatabaseCount('sensitive_access_events', 0);
    }

    public function test_sensitive_download_audit_is_idempotent(): void
    {
        [$started] = $this->availableDocument('confidential', 'sensitive-download');
        $service = $this->downloadService(new AcceptanceDecideAccess, new AcceptanceLinkedFacts);
        $request = new DocumentAccessRequest(
            self::CREATOR_ID,
            self::OWNER_ID,
            '018f6f7d-0c00-0000-0000-000000000931',
            '192.0.2.10',
            str_repeat('a', 64),
            'sensitive-download-once',
        );

        $service->download($started->documentId, $started->versionId, $request);
        $service->download($started->documentId, $started->versionId, $request);

        $this->assertDatabaseCount('sensitive_access_events', 1);
        $this->assertDatabaseHas('sensitive_access_events', [
            'resource_type' => 'document',
            'resource_id' => $started->documentId,
            'action' => 'download',
            'classification_code' => 'confidential',
        ]);
    }

    /** @return array{0:object,1:InMemoryPrivateObjectStorage,2:DocumentUploadHandler} */
    private function availableDocument(string $classification, string $key): array
    {
        $storage = new InMemoryPrivateObjectStorage;
        $handler = $this->handler($storage, new InMemoryMalwareScanner);
        $started = $this->initiate($handler, $key.'.pdf', 'application/pdf', $key, $classification);
        $properties = new StoredObjectProperties($this->hashFor($key), 512, 'application/pdf', 'etag-'.$key, 'generation-'.$key);
        $storage->completeUpload($started->uploadIntent->id, $properties);
        $handler->complete(
            $this->actor(DocumentUploadHandler::COMPLETE_OPERATION),
            $started->uploadIntent->id,
            new CompleteDocumentUpload($properties->sha256, $properties->sizeBytes),
            $this->idempotency(DocumentUploadHandler::COMPLETE_OPERATION, $key.'-complete', $key.'-complete'),
        );
        $handler->scanVersion(
            $this->actor(DocumentUploadHandler::SCAN_OPERATION),
            $started->versionId,
            $this->idempotency(DocumentUploadHandler::SCAN_OPERATION, $key.'-scan', $key.'-scan'),
        );
        $handler->reconcilePromotion(
            $this->actor(DocumentUploadHandler::RECONCILE_PROMOTION_OPERATION),
            $started->versionId,
            $this->idempotency(DocumentUploadHandler::RECONCILE_PROMOTION_OPERATION, $key.'-promote', $key.'-promote'),
        );

        return [$started, $storage, $handler];
    }

    private function downloadService(DecideAccess $access, LinkedResourceAuthorizationFacts $facts): DocumentDownloadService
    {
        return new DocumentDownloadService(
            $access,
            $facts,
            $this->app->make(\Modules\Documents\Application\DocumentAuthorizationRecordFactsBuilder::class),
            new class implements DocumentDownloadGrantIssuer
            {
                public function issue(string $documentId, string $versionId, string $principalId): DocumentDownloadGrant
                {
                    return new DocumentDownloadGrant($documentId, $versionId, 'https://download.invalid/'.$versionId, new DateTimeImmutable('+5 minutes'), 'test-correlation');
                }
            },
            new DatabaseSensitiveAccessEventRecorder($this->app->make(RecordSensitiveAccessEvent::class)),
        );
    }

    private function handler(InMemoryPrivateObjectStorage $storage, InMemoryMalwareScanner $scanner): DocumentUploadHandler
    {
        return new DocumentUploadHandler($storage, $scanner, DocumentUploadPolicy::fromConfig(config('documents')), DocumentRetentionPolicy::fromConfig(config('documents')), $this->app->make(TransactionalOutbox::class));
    }

    private function initiate(DocumentUploadHandler $handler, string $filename, string $mime, string $key, string $classification = 'internal'): object
    {
        return $handler->initiate(
            $this->actor(DocumentUploadHandler::INITIATE_OPERATION),
            new InitiateDocumentUpload('document_version', new DocumentMetadata('اختبار ملف', null, $classification), new UploadFileMetadata($filename, 512, $mime, $this->hashFor($key))),
            $this->idempotency(DocumentUploadHandler::INITIATE_OPERATION, $key.'-initiate', $key.'-initiate'),
        );
    }

    private function actor(string $operation): AuthorizedDocumentActor
    {
        return AuthorizedDocumentActor::fromTrustedContext(new InMemoryTrustedDocumentAuthorizationContext(self::CREATOR_ID, self::OWNER_ID, '018f6f7d-0c00-7000-8000-000000000999', [$operation]), $operation);
    }

    private function idempotency(string $operation, string $key, string $request): IdempotencyContext
    {
        return new IdempotencyContext(self::CREATOR_ID, $operation, $key, hash('sha256', $request));
    }

    private function hashFor(string $value): string
    {
        return hash('sha256', $value);
    }
}

final class AcceptanceDecideAccess implements DecideAccess
{
    /** @param list<string> $deniedCapabilities */
    public function __construct(private readonly array $deniedCapabilities = []) {}

    /**
     * Test doubles persist nothing, so the read-side evaluation IS decide().
     */
    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return new AccessDecision(
            in_array($capability, $this->deniedCapabilities, true) ? 'deny' : 'allow',
            $capability,
            $facts instanceof RecordFacts ? $facts->resourceType : 'document',
            in_array($capability, $this->deniedCapabilities, true) ? ['test_denied'] : ['test_allowed'],
            'test-policy-v1',
            'test-facts-v1',
            $facts instanceof RecordFacts ? $facts->classification : 'internal',
        );
    }
}

final class AcceptanceLinkedFacts implements LinkedResourceAuthorizationFacts
{
    private const OWNER_ID = '018f6f7d-0c00-7000-8000-000000000801';

    public function resolve(DocumentSourceReference $reference): RecordFacts
    {
        return new RecordFacts(self::OWNER_ID, $reference->sourceType, 'confidential');
    }
}
