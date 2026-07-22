<?php

namespace Modules\Documents\Tests;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Documents\Application\AuthorizedDocumentActor;
use Modules\Documents\Application\CleanSpreadsheetDocument;
use Modules\Documents\Application\CleanSpreadsheetParseResult;
use Modules\Documents\Application\CompleteDocumentUpload;
use Modules\Documents\Application\DocumentMetadata;
use Modules\Documents\Application\IdempotencyContext;
use Modules\Documents\Application\InitiateDocumentUpload;
use Modules\Documents\Application\RetryableStorageException;
use Modules\Documents\Application\StoredObjectProperties;
use Modules\Documents\Application\UploadFileMetadata;
use Modules\Documents\Contracts\CleanSpreadsheetParser;
use Modules\Documents\Domain\DocumentRetentionPolicy;
use Modules\Documents\Domain\DocumentUploadPolicy;
use Modules\Documents\Features\Spreadsheet\CleanSpreadsheetReferenceService;
use Modules\Documents\Features\Upload\DocumentUploadHandler;
use Modules\Documents\Infrastructure\Storage\PrivateDocumentDiskConfiguration;
use Modules\Documents\Tests\Support\InMemoryMalwareScanner;
use Modules\Documents\Tests\Support\InMemoryPrivateObjectStorage;
use Modules\Documents\Tests\Support\InMemoryTrustedDocumentAuthorizationContext;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use UnexpectedValueException;

final class DocumentUploadCoreTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_ID = '018f6f7d-0c00-7000-8000-000000000801';

    private const OTHER_OWNER_ID = '018f6f7d-0c00-7000-8000-000000000803';

    private const CREATOR_ID = '018f6f7d-0c00-7000-8000-000000000802';

    private InMemoryPrivateObjectStorage $storage;

    private InMemoryMalwareScanner $scanner;

    private DocumentUploadHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new InMemoryPrivateObjectStorage;
        $this->scanner = new InMemoryMalwareScanner;
        $this->handler = new DocumentUploadHandler(
            $this->storage,
            $this->scanner,
            DocumentUploadPolicy::fromConfig(config('documents')),
            DocumentRetentionPolicy::fromConfig(config('documents')),
        );
    }

    public function test_authorized_actor_owns_document_and_configured_retention_not_caller_metadata(): void
    {
        $started = $this->initiate('retained.pdf', 'application/pdf', 512, 'retention');

        $this->assertMatchesRegularExpression(self::uuidV7Pattern(), $started->documentId);
        $this->assertMatchesRegularExpression(self::uuidV7Pattern(), $started->versionId);
        $this->assertMatchesRegularExpression(self::uuidV7Pattern(), $started->uploadIntent->id);
        $document = DB::table('documents')->where('public_id', $started->documentId)->first();
        $this->assertNotNull($document);
        $this->assertSame(self::OWNER_ID, $document->owner_organization_unit_id);
        $this->assertSame(self::CREATOR_ID, $document->created_by_user_id);
        $this->assertSame('administrative_7_years', $document->retention_policy_key);
        $this->assertSame(0, (int) $document->legal_hold);
        $this->assertGreaterThan(now('UTC')->getTimestamp(), strtotime((string) $document->retention_until));

        $topSecret = $this->initiate('secret.pdf', 'application/pdf', 512, 'top-secret', 'top_secret');
        $this->assertDatabaseHas('documents', [
            'public_id' => $topSecret->documentId,
            'retention_policy_key' => 'top_secret_15_years',
            'legal_hold' => 1,
            'legal_hold_reason' => 'classification_policy',
            'status' => 'held',
        ]);
    }

    public function test_initiating_a_version_targets_the_existing_document_and_issues_a_quarantine_intent(): void
    {
        $first = $this->initiate('first.pdf', 'application/pdf', 512, 'existing-version-first');
        $request = new InitiateDocumentUpload(
            'document_version',
            new DocumentMetadata('ignored for existing document', null, 'internal'),
            new UploadFileMetadata('second.pdf', 1024, 'application/pdf', $this->hashFor('second.pdf', 1024)),
            $first->documentId,
        );

        $second = $this->handler->initiate(
            $this->actor(DocumentUploadHandler::INITIATE_OPERATION),
            $request,
            $this->idempotency(DocumentUploadHandler::INITIATE_OPERATION, 'existing-version-second', 'existing-version-second'),
        );

        $this->assertSame($first->documentId, $second->documentId);
        $this->assertNotSame($first->versionId, $second->versionId);
        $this->assertSame(1, DB::table('documents')->where('public_id', $first->documentId)->count());
        $this->assertSame(2, DB::table('document_versions')->where('document_id', DB::table('documents')->where('public_id', $first->documentId)->value('id'))->count());
        $this->assertSame($first->documentId, DB::table('document_upload_intents')->where('id', $second->uploadIntent->id)->value('document_id')
            ? DB::table('documents')->where('id', DB::table('document_upload_intents')->where('id', $second->uploadIntent->id)->value('document_id'))->value('public_id')
            : null);
    }

    public function test_signed_intent_binds_exact_content_conditions_and_opaque_root_relative_key(): void
    {
        $started = $this->initiate('bound.pdf', 'application/pdf', 512, 'signed-conditions');

        $this->assertSame([
            'Content-Length' => '512',
            'Content-Type' => 'application/pdf',
            'x-amz-checksum-sha256' => base64_encode(hex2bin($this->hashFor('bound.pdf', 512))),
            'If-None-Match' => '*',
        ], $started->uploadIntent->requiredHeaders);
        $storage = DB::table('document_storage_objects')->first();
        $this->assertNotNull($storage);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.blob\z/', (string) $storage->object_key);
        $this->assertStringNotContainsString('/', (string) $storage->object_key);
        $response = json_encode($started->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('object_key', $response);
        $this->assertStringNotContainsString('storage_object_id', $response);
        $this->assertStringNotContainsString('storage.invalid', (string) DB::table('document_upload_intents')->value('signed_intent_payload'));

        $replayed = $this->initiate('bound.pdf', 'application/pdf', 512, 'signed-conditions');
        $this->assertSame($started->uploadIntent->url, $replayed->uploadIntent->url);
        $this->assertSame(1, $this->storage->issuedIntentCalls);
    }

    public function test_signed_intent_accepts_canonical_lowercase_signer_headers(): void
    {
        $this->storage->returnCanonicalLowercaseIntent = true;

        $started = $this->initiate('canonical-lowercase.pdf', 'application/pdf', 512, 'canonical-lowercase');

        $this->assertSame([
            'content-length' => '512',
            'content-type' => 'application/pdf',
            'x-amz-checksum-sha256' => base64_encode(hex2bin($this->hashFor('canonical-lowercase.pdf', 512))),
            'if-none-match' => '*',
        ], $started->uploadIntent->requiredHeaders);
    }

    public function test_malformed_storage_signature_rolls_back_intent_creation(): void
    {
        $this->storage->returnMalformedIntent = true;

        try {
            $this->handler->initiate(
                $this->actor(DocumentUploadHandler::INITIATE_OPERATION),
                $this->initiationRequest('bad-signature.pdf', 'application/pdf', 512),
                $this->idempotency(DocumentUploadHandler::INITIATE_OPERATION, 'bad-signature', 'bad-signature'),
            );
            $this->fail('Unsigned content conditions must reject intent creation.');
        } catch (UnexpectedValueException) {
            $this->assertDatabaseCount('documents', 0);
            $this->assertDatabaseCount('document_upload_intents', 0);
        }
    }

    public function test_signed_url_expiry_and_endpoint_are_bounded_by_the_persisted_intent_policy(): void
    {
        $this->assertSame(['storage.invalid'], config('documents.storage.upload_endpoint_allowlist'));
        $this->storage->intentExpiryOffsetSeconds = 1;

        try {
            $this->initiate('long-lived.pdf', 'application/pdf', 512, 'long-lived');
            $this->fail('A signed URL must not outlive its database upload intent.');
        } catch (UnexpectedValueException $exception) {
            $this->assertStringContainsString('expiry', $exception->getMessage());
        }

        $this->storage->intentExpiryOffsetSeconds = 0;
        $this->storage->intentUrlOverride = 'https://untrusted.invalid/upload';
        try {
            $this->initiate('untrusted-endpoint.pdf', 'application/pdf', 512, 'untrusted-endpoint');
            $this->fail('A signed URL must use an allowlisted endpoint.');
        } catch (UnexpectedValueException $exception) {
            $this->assertStringContainsString('allowlisted', $exception->getMessage());
        }
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_conditional_create_is_one_shot_and_ownership_is_checked_before_completion(): void
    {
        $started = $this->initiate('one-shot.pdf', 'application/pdf', 512, 'one-shot');
        $properties = $this->properties('one-shot.pdf', 512, 'application/pdf');
        $this->storage->completeUpload($started->uploadIntent->id, $properties);

        try {
            $this->storage->completeUpload($started->uploadIntent->id, $properties);
            $this->fail('A conditional create must reject a second object write.');
        } catch (DomainException $exception) {
            $this->assertSame('conditional_create_failed', $exception->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('document_owner_organization_mismatch');
        $this->handler->complete(
            $this->actor(DocumentUploadHandler::COMPLETE_OPERATION, self::OTHER_OWNER_ID),
            $started->uploadIntent->id,
            new CompleteDocumentUpload($properties->sha256, 512),
            new IdempotencyContext(self::CREATOR_ID, DocumentUploadHandler::COMPLETE_OPERATION, 'other-owner', hash('sha256', 'other-owner')),
        );
    }

    public function test_completion_binds_expected_hash_size_mime_etag_and_generation_before_scan(): void
    {
        $started = $this->initiate('members.csv', 'text/csv', 512, 'invalid-completion');
        $actual = new StoredObjectProperties(hash('sha256', 'actual'), 256, 'application/pdf', 'etag-actual', 'generation-2');
        $this->storage->completeUpload($started->uploadIntent->id, $actual);

        $completed = $this->handler->complete(
            $this->actor(DocumentUploadHandler::COMPLETE_OPERATION),
            $started->uploadIntent->id,
            new CompleteDocumentUpload($actual->sha256, 256),
            $this->idempotency(DocumentUploadHandler::COMPLETE_OPERATION, 'invalid-completion', 'invalid-completion'),
        );

        $this->assertFalse($completed->accepted);
        $this->assertSame(['sha256_mismatch', 'size_mismatch', 'mime_extension_mismatch'], $completed->failureCodes);
        $this->assertDatabaseHas('document_storage_objects', ['etag' => 'etag-actual', 'generation' => 'generation-2']);
        $this->assertDatabaseHas('document_quarantines', ['policy_verdict' => 'blocked']);
    }

    public function test_transient_inspection_failure_is_retryable_without_consuming_intent_or_idempotency_key(): void
    {
        $started = $this->initiate('retry.pdf', 'application/pdf', 512, 'retry');
        $properties = $this->properties('retry.pdf', 512, 'application/pdf');
        $this->storage->completeUpload($started->uploadIntent->id, $properties);
        $idempotency = $this->idempotency(DocumentUploadHandler::COMPLETE_OPERATION, 'retry-complete', 'retry-complete');
        $this->storage->failNextInspectionRetryably();

        try {
            $this->handler->complete($this->actor(DocumentUploadHandler::COMPLETE_OPERATION), $started->uploadIntent->id, new CompleteDocumentUpload($properties->sha256, 512), $idempotency);
            $this->fail('Transient inspection failures must surface for retry.');
        } catch (RetryableStorageException $exception) {
            $this->assertSame('storage_inspection_retryable', $exception->getMessage());
        }
        $this->assertDatabaseHas('document_upload_intents', ['id' => $started->uploadIntent->id, 'completed_at' => null]);
        $this->assertDatabaseCount('document_idempotency_keys', 1);

        $completed = $this->handler->complete($this->actor(DocumentUploadHandler::COMPLETE_OPERATION), $started->uploadIntent->id, new CompleteDocumentUpload($properties->sha256, 512), $idempotency);
        $this->assertTrue($completed->accepted);
    }

    public function test_clean_scan_stays_promotion_pending_until_idempotent_post_commit_reconciliation(): void
    {
        $started = $this->initiate('clean.pdf', 'application/pdf', 512, 'clean');
        $properties = $this->properties('clean.pdf', 512, 'application/pdf');
        $this->completeCleanUpload($started->uploadIntent->id, $properties, 'clean-complete');

        $scanned = $this->handler->scanVersion(
            $this->actor(DocumentUploadHandler::SCAN_OPERATION),
            $started->versionId,
            $this->idempotency(DocumentUploadHandler::SCAN_OPERATION, 'clean-scan', 'clean-scan'),
        );
        $this->assertSame('clean', $scanned->scanStatus);
        $this->assertSame('promotion_pending', $scanned->availabilityStatus);
        $this->assertSame(0, $this->storage->promotionCalls);
        $this->assertDatabaseHas('document_versions', ['public_id' => $started->versionId, 'availability_status' => 'promotion_pending']);

        $reconcileIdempotency = $this->idempotency(DocumentUploadHandler::RECONCILE_PROMOTION_OPERATION, 'clean-promote', 'clean-promote');
        $available = $this->handler->reconcilePromotion(
            $this->actor(DocumentUploadHandler::RECONCILE_PROMOTION_OPERATION),
            $started->versionId,
            $reconcileIdempotency,
        );
        $this->assertSame('available', $available->availabilityStatus);
        $this->assertSame(1, $this->storage->promotionCalls);
        $replay = $this->handler->reconcilePromotion(
            $this->actor(DocumentUploadHandler::RECONCILE_PROMOTION_OPERATION),
            $started->versionId,
            $reconcileIdempotency,
        );
        $this->assertSame('available', $replay->availabilityStatus);
        $this->assertSame(1, $this->storage->promotionCalls);
        $this->assertDatabaseHas('documents', ['public_id' => $started->documentId, 'status' => 'active']);
    }

    public function test_configured_legal_hold_is_preserved_through_promotion_and_denies_spreadsheet_references(): void
    {
        $mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $started = $this->initiate('held.xlsx', $mimeType, 512, 'held', 'top_secret');
        $properties = $this->properties('held.xlsx', 512, $mimeType);
        $this->assertDatabaseHas('documents', ['public_id' => $started->documentId, 'legal_hold' => 1, 'status' => 'held']);
        $this->completeCleanUpload($started->uploadIntent->id, $properties, 'held-complete');
        $this->handler->scanVersion(
            $this->actor(DocumentUploadHandler::SCAN_OPERATION),
            $started->versionId,
            $this->idempotency(DocumentUploadHandler::SCAN_OPERATION, 'held-scan', 'held-scan'),
        );
        $available = $this->handler->reconcilePromotion(
            $this->actor(DocumentUploadHandler::RECONCILE_PROMOTION_OPERATION),
            $started->versionId,
            $this->idempotency(DocumentUploadHandler::RECONCILE_PROMOTION_OPERATION, 'held-promote', 'held-promote'),
        );
        $this->assertSame('available', $available->availabilityStatus);
        $this->assertDatabaseHas('documents', ['public_id' => $started->documentId, 'status' => 'held']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('document_held');
        (new CleanSpreadsheetReferenceService)->fromVerifiedAvailableVersion(
            $this->actor(CleanSpreadsheetReferenceService::OPERATION),
            $started->versionId,
        );
    }

    public function test_promotion_requires_matching_etag_and_generation_before_making_a_version_available(): void
    {
        foreach (['etag', 'generation'] as $mismatchedProperty) {
            $started = $this->initiate($mismatchedProperty.'-mismatch.pdf', 'application/pdf', 512, $mismatchedProperty.'-mismatch');
            $properties = $this->properties($mismatchedProperty.'-mismatch.pdf', 512, 'application/pdf');
            $this->completeCleanUpload($started->uploadIntent->id, $properties, $mismatchedProperty.'-mismatch-complete');
            $this->handler->scanVersion(
                $this->actor(DocumentUploadHandler::SCAN_OPERATION),
                $started->versionId,
                $this->idempotency(DocumentUploadHandler::SCAN_OPERATION, $mismatchedProperty.'-mismatch-scan', $mismatchedProperty.'-mismatch-scan'),
            );
            $this->storage->postPromotionPropertiesOverride = new StoredObjectProperties(
                $properties->sha256,
                $properties->sizeBytes,
                $properties->detectedMimeType,
                $mismatchedProperty === 'etag' ? 'unexpected-etag' : $properties->etag,
                $mismatchedProperty === 'generation' ? 'unexpected-generation' : $properties->generation,
            );

            try {
                $this->handler->reconcilePromotion(
                    $this->actor(DocumentUploadHandler::RECONCILE_PROMOTION_OPERATION),
                    $started->versionId,
                    $this->idempotency(DocumentUploadHandler::RECONCILE_PROMOTION_OPERATION, $mismatchedProperty.'-mismatch-promote', $mismatchedProperty.'-mismatch-promote'),
                );
                $this->fail('A promotion with a mismatched '.$mismatchedProperty.' must remain unavailable.');
            } catch (DomainException $exception) {
                $this->assertSame('document_promotion_integrity_mismatch', $exception->getMessage());
            }

            $this->assertDatabaseHas('document_versions', [
                'public_id' => $started->versionId,
                'availability_status' => 'promotion_pending',
            ]);
            $this->assertDatabaseMissing('document_idempotency_keys', [
                'operation' => DocumentUploadHandler::RECONCILE_PROMOTION_OPERATION,
                'key' => $mismatchedProperty.'-mismatch-promote',
            ]);
            $this->storage->postPromotionPropertiesOverride = null;
        }
    }

    public function test_idempotency_replay_refuses_a_record_bound_to_another_resource(): void
    {
        $started = $this->initiate('replay.pdf', 'application/pdf', 512, 'replay');
        $properties = $this->properties('replay.pdf', 512, 'application/pdf');
        $this->storage->completeUpload($started->uploadIntent->id, $properties);
        $idempotency = $this->idempotency(DocumentUploadHandler::COMPLETE_OPERATION, 'replay-complete', 'replay-complete');
        $this->handler->complete(
            $this->actor(DocumentUploadHandler::COMPLETE_OPERATION),
            $started->uploadIntent->id,
            new CompleteDocumentUpload($properties->sha256, 512),
            $idempotency,
        );
        DB::table('document_idempotency_keys')
            ->where('principal_id', self::CREATOR_ID)
            ->where('operation', DocumentUploadHandler::COMPLETE_OPERATION)
            ->update(['resource_id' => '018f6f7d-0c00-7000-8000-000000000898']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('idempotency_resource_mismatch');
        $this->handler->complete(
            $this->actor(DocumentUploadHandler::COMPLETE_OPERATION),
            $started->uploadIntent->id,
            new CompleteDocumentUpload($properties->sha256, 512),
            $idempotency,
        );
    }

    public function test_transient_scan_and_promotion_failures_remain_quarantined_or_pending_for_retry(): void
    {
        $started = $this->initiate('scan-retry.pdf', 'application/pdf', 512, 'scan-retry');
        $properties = $this->properties('scan-retry.pdf', 512, 'application/pdf');
        $this->completeCleanUpload($started->uploadIntent->id, $properties, 'scan-retry-complete');
        $this->storage->failNextInspectionRetryably();

        $this->expectException(RetryableStorageException::class);
        $this->handler->scanVersion(
            $this->actor(DocumentUploadHandler::SCAN_OPERATION),
            $started->versionId,
            $this->idempotency(DocumentUploadHandler::SCAN_OPERATION, 'scan-retry', 'scan-retry'),
        );
    }

    public function test_scanner_unavailability_and_generation_change_fail_closed_without_promotion(): void
    {
        $scannerUnavailable = $this->initiate('unavailable.pdf', 'application/pdf', 512, 'unavailable');
        $properties = $this->properties('unavailable.pdf', 512, 'application/pdf');
        $this->completeCleanUpload($scannerUnavailable->uploadIntent->id, $properties, 'unavailable-complete');
        $this->scanner->returnUnavailable();
        $unavailable = $this->handler->scanVersion(
            $this->actor(DocumentUploadHandler::SCAN_OPERATION),
            $scannerUnavailable->versionId,
            $this->idempotency(DocumentUploadHandler::SCAN_OPERATION, 'unavailable-scan', 'unavailable-scan'),
        );
        $this->assertSame('failed', $unavailable->scanStatus);
        $this->assertSame('quarantined', $unavailable->availabilityStatus);

        $tampered = $this->initiate('tampered.pdf', 'application/pdf', 512, 'tampered');
        $tamperedProperties = $this->properties('tampered.pdf', 512, 'application/pdf');
        $this->completeCleanUpload($tampered->uploadIntent->id, $tamperedProperties, 'tampered-complete');
        $this->storage->replaceUpload(
            $tampered->uploadIntent->id,
            new StoredObjectProperties($tamperedProperties->sha256, 512, 'application/pdf', 'etag-replaced', 'generation-replaced'),
        );
        $blocked = $this->handler->scanVersion(
            $this->actor(DocumentUploadHandler::SCAN_OPERATION),
            $tampered->versionId,
            $this->idempotency(DocumentUploadHandler::SCAN_OPERATION, 'tampered-scan', 'tampered-scan'),
        );
        $this->assertSame('rejected', $blocked->availabilityStatus);
        $this->assertSame(0, $this->storage->promotionCalls);
    }

    public function test_infected_content_is_rejected_without_ever_requesting_promotion(): void
    {
        $started = $this->initiate('infected.pdf', 'application/pdf', 512, 'infected');
        DB::table('documents')->where('public_id', $started->documentId)->update(['status' => 'held']);
        $properties = $this->properties('infected.pdf', 512, 'application/pdf');
        $this->completeCleanUpload($started->uploadIntent->id, $properties, 'infected-complete');
        $this->scanner->returnInfected();

        $scanned = $this->handler->scanVersion(
            $this->actor(DocumentUploadHandler::SCAN_OPERATION),
            $started->versionId,
            $this->idempotency(DocumentUploadHandler::SCAN_OPERATION, 'infected-scan', 'infected-scan'),
        );
        $this->assertSame('infected', $scanned->scanStatus);
        $this->assertSame('rejected', $scanned->availabilityStatus);
        $this->assertSame(0, $this->storage->promotionCalls);
        $this->assertDatabaseHas('documents', ['public_id' => $started->documentId, 'status' => 'held']);
    }

    public function test_retryable_promotion_leaves_the_committed_clean_version_pending_for_reconciliation(): void
    {
        $started = $this->initiate('promotion-retry.pdf', 'application/pdf', 512, 'promotion-retry');
        $properties = $this->properties('promotion-retry.pdf', 512, 'application/pdf');
        $this->completeCleanUpload($started->uploadIntent->id, $properties, 'promotion-retry-complete');
        $this->handler->scanVersion($this->actor(DocumentUploadHandler::SCAN_OPERATION), $started->versionId, $this->idempotency(DocumentUploadHandler::SCAN_OPERATION, 'promotion-retry-scan', 'promotion-retry-scan'));
        $this->storage->failNextPromotionRetryably();
        $idempotency = $this->idempotency(DocumentUploadHandler::RECONCILE_PROMOTION_OPERATION, 'promotion-retry-promote', 'promotion-retry-promote');

        try {
            $this->handler->reconcilePromotion($this->actor(DocumentUploadHandler::RECONCILE_PROMOTION_OPERATION), $started->versionId, $idempotency);
            $this->fail('Transient promotion must be retried, never marked available.');
        } catch (RetryableStorageException $exception) {
            $this->assertSame('storage_promotion_retryable', $exception->getMessage());
        }
        $this->assertDatabaseHas('document_versions', ['public_id' => $started->versionId, 'availability_status' => 'promotion_pending']);
        $this->assertDatabaseMissing('document_idempotency_keys', ['operation' => DocumentUploadHandler::RECONCILE_PROMOTION_OPERATION]);

        $available = $this->handler->reconcilePromotion($this->actor(DocumentUploadHandler::RECONCILE_PROMOTION_OPERATION), $started->versionId, $idempotency);
        $this->assertSame('available', $available->availabilityStatus);
        $this->assertSame(1, $this->storage->promotionCalls);
    }

    public function test_clean_spreadsheet_reference_requires_available_verified_provenance_and_private_constructor(): void
    {
        $constructor = new ReflectionMethod(CleanSpreadsheetDocument::class, '__construct');
        $this->assertFalse($constructor->isPublic());
        $started = $this->initiate('people.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 512, 'spreadsheet');
        $properties = $this->properties('people.xlsx', 512, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->completeCleanUpload($started->uploadIntent->id, $properties, 'spreadsheet-complete');
        $references = new CleanSpreadsheetReferenceService;

        try {
            $references->fromVerifiedAvailableVersion($this->actor(CleanSpreadsheetReferenceService::OPERATION), $started->versionId);
            $this->fail('Quarantined content must not produce a spreadsheet reference.');
        } catch (DomainException $exception) {
            $this->assertSame('clean_spreadsheet_provenance_unavailable', $exception->getMessage());
        }
        $this->handler->scanVersion($this->actor(DocumentUploadHandler::SCAN_OPERATION), $started->versionId, $this->idempotency(DocumentUploadHandler::SCAN_OPERATION, 'spreadsheet-scan', 'spreadsheet-scan'));
        $this->handler->reconcilePromotion($this->actor(DocumentUploadHandler::RECONCILE_PROMOTION_OPERATION), $started->versionId, $this->idempotency(DocumentUploadHandler::RECONCILE_PROMOTION_OPERATION, 'spreadsheet-promote', 'spreadsheet-promote'));

        $reference = $references->fromVerifiedAvailableVersion($this->actor(CleanSpreadsheetReferenceService::OPERATION), $started->versionId);
        $parser = new class implements CleanSpreadsheetParser
        {
            public function parse(CleanSpreadsheetDocument $document): CleanSpreadsheetParseResult
            {
                return new CleanSpreadsheetParseResult($document->sourceFilename, $document->format, ['employee_number'], [['employee_number' => 'EMP-001']]);
            }
        };
        $this->assertSame('xlsx', $parser->parse($reference)->format);
    }

    public function test_hardening_migration_and_private_disk_configuration_fail_closed_outside_testing(): void
    {
        $this->assertTrue(Schema::hasColumns('document_storage_objects', ['etag', 'generation']));
        $this->assertTrue(Schema::hasColumns('document_upload_intents', ['purpose', 'expected_sha256', 'expected_size_bytes', 'expected_mime_type', 'conditional_create', 'signed_intent_payload']));
        $this->assertTrue(Schema::hasColumn('document_versions', 'promotion_requested_at'));
        $this->assertSame('documents/quarantine', config('filesystems.disks.documents-quarantine.root'));
        $this->assertSame('documents/available', config('filesystems.disks.documents-available.root'));

        try {
            PrivateDocumentDiskConfiguration::assertRuntimeSafe(false, ['key' => null, 'secret' => null, 'region' => null, 'bucket' => null, 'kms_key_id' => null], ['key' => null, 'secret' => null, 'region' => null, 'bucket' => null, 'kms_key_id' => null]);
            $this->fail('Non-testing storage must require dedicated credentials and buckets.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('dedicated', $exception->getMessage());
        }
        $valid = ['key' => 'key-a', 'secret' => 'secret-a', 'region' => 'region-a', 'bucket' => 'bucket-a', 'kms_key_id' => 'kms-a'];
        try {
            PrivateDocumentDiskConfiguration::assertRuntimeSafe(false, $valid, $valid);
            $this->fail('Quarantine and available storage must remain physically and credentially separated.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('separate', $exception->getMessage());
        }
    }

    private function initiate(string $filename, string $mimeType, int $sizeBytes, string $key, string $classification = 'internal'): object
    {
        return $this->handler->initiate(
            $this->actor(DocumentUploadHandler::INITIATE_OPERATION),
            $this->initiationRequest($filename, $mimeType, $sizeBytes, $classification),
            $this->idempotency(DocumentUploadHandler::INITIATE_OPERATION, $key, 'initiation:'.$filename.':'.$mimeType.':'.$sizeBytes.':'.$classification),
        );
    }

    private function completeCleanUpload(string $uploadIntentId, StoredObjectProperties $properties, string $key): void
    {
        $this->storage->completeUpload($uploadIntentId, $properties);
        $completed = $this->handler->complete(
            $this->actor(DocumentUploadHandler::COMPLETE_OPERATION),
            $uploadIntentId,
            new CompleteDocumentUpload($properties->sha256, $properties->sizeBytes),
            $this->idempotency(DocumentUploadHandler::COMPLETE_OPERATION, $key, $key),
        );
        $this->assertTrue($completed->accepted);
    }

    private function initiationRequest(string $filename, string $mimeType, int $sizeBytes, string $classification = 'internal'): InitiateDocumentUpload
    {
        return new InitiateDocumentUpload(
            'document_version',
            new DocumentMetadata('ملف إداري', 'وصف محكوم', $classification),
            new UploadFileMetadata($filename, $sizeBytes, $mimeType, $this->hashFor($filename, $sizeBytes)),
        );
    }

    private function properties(string $filename, int $sizeBytes, string $mimeType): StoredObjectProperties
    {
        return new StoredObjectProperties(
            $this->hashFor($filename, $sizeBytes),
            $sizeBytes,
            $mimeType,
            'etag-'.substr($this->hashFor($filename, $sizeBytes), 0, 16),
            'generation-'.substr($this->hashFor($filename, $sizeBytes), 0, 12),
        );
    }

    private function actor(string $operation, string $organizationUnitId = self::OWNER_ID): AuthorizedDocumentActor
    {
        return AuthorizedDocumentActor::fromTrustedContext(new InMemoryTrustedDocumentAuthorizationContext(
            self::CREATOR_ID,
            $organizationUnitId,
            '018f6f7d-0c00-7000-8000-000000000899',
            [$operation],
        ), $operation);
    }

    private function idempotency(string $operation, string $key, string $request): IdempotencyContext
    {
        return new IdempotencyContext(self::CREATOR_ID, $operation, $key, hash('sha256', $request));
    }

    private function hashFor(string $filename, int $sizeBytes): string
    {
        return hash('sha256', $filename.':'.$sizeBytes);
    }

    private static function uuidV7Pattern(): string
    {
        return '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';
    }
}
