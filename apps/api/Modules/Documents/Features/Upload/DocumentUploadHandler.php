<?php

namespace Modules\Documents\Features\Upload;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;
use Modules\Documents\Application\AuthorizedDocumentActor;
use Modules\Documents\Application\CompleteDocumentUpload;
use Modules\Documents\Application\DocumentScanResult;
use Modules\Documents\Application\DocumentUploadCompletion;
use Modules\Documents\Application\IdempotencyContext;
use Modules\Documents\Application\InitiatedDocumentUpload;
use Modules\Documents\Application\InitiateDocumentUpload;
use Modules\Documents\Application\MalwareScanResult;
use Modules\Documents\Application\QuarantineObjectReference;
use Modules\Documents\Application\QuarantineUploadRequest;
use Modules\Documents\Application\RetryableStorageException;
use Modules\Documents\Application\SignedUploadIntent;
use Modules\Documents\Application\StoredObjectProperties;
use Modules\Documents\Application\UploadFileMetadata;
use Modules\Documents\Application\VerifiedQuarantineObject;
use Modules\Documents\Contracts\MalwareScanner;
use Modules\Documents\Contracts\PrivateObjectStorage;
use Modules\Documents\Domain\DocumentRetentionPolicy;
use Modules\Documents\Domain\DocumentScanStatus;
use Modules\Documents\Domain\DocumentStatus;
use Modules\Documents\Domain\DocumentUploadPolicy;
use Modules\Documents\Domain\DocumentVersionAvailabilityStatus;
use Modules\Documents\Domain\UuidV7;
use stdClass;
use Throwable;
use UnexpectedValueException;

final class DocumentUploadHandler
{
    public const INITIATE_OPERATION = 'documents.initiate-upload';

    public const COMPLETE_OPERATION = 'documents.complete-upload';

    public const SCAN_OPERATION = 'documents.scan-version';

    public const RECONCILE_PROMOTION_OPERATION = 'documents.reconcile-promotion';

    public function __construct(
        private readonly PrivateObjectStorage $storage,
        private readonly MalwareScanner $scanner,
        private readonly DocumentUploadPolicy $uploadPolicy,
        private readonly DocumentRetentionPolicy $retentionPolicy,
    ) {}

    public function initiate(
        AuthorizedDocumentActor $actor,
        InitiateDocumentUpload $request,
        IdempotencyContext $idempotency,
    ): InitiatedDocumentUpload {
        $this->assertActor($actor, self::INITIATE_OPERATION, $actor->organizationUnitId, $idempotency);
        $this->uploadPolicy->assertCanInitiate($request->file);

        $existing = $this->idempotencyQuery($idempotency)->first();
        if ($existing instanceof stdClass) {
            return $this->replayInitiation($actor, $existing, $idempotency);
        }

        return DB::transaction(function () use ($actor, $request, $idempotency): InitiatedDocumentUpload {
            $existing = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
            if ($existing instanceof stdClass) {
                return $this->replayInitiation($actor, $existing, $idempotency);
            }

            $now = $this->now();
            $targetDocument = null;
            if ($request->documentId !== null) {
                UuidV7::assert($request->documentId, 'Document id');
                $targetDocument = DB::table('documents')->where('public_id', $request->documentId)->lockForUpdate()->first();
                if (! $targetDocument instanceof stdClass) {
                    throw new DomainException('document_not_found');
                }
                if ((string) $targetDocument->classification !== $request->metadata->classification) {
                    throw new DomainException('document_classification_mismatch');
                }
            }
            $retention = $targetDocument === null
                ? $this->retentionPolicy->resolve($request->metadata->classification, $now)
                : null;
            $expiresAt = $now->modify('+'.$this->uploadIntentTtlSeconds().' seconds');
            $documentId = $targetDocument->id ?? UuidV7::generate();
            $documentPublicId = $targetDocument->public_id ?? UuidV7::generate();
            $versionId = UuidV7::generate();
            $versionPublicId = UuidV7::generate();
            $storageObjectId = UuidV7::generate();
            $uploadIntentId = UuidV7::generate();
            if (! $this->claimIdempotency($idempotency, 'upload_intent', $uploadIntentId, [
                'document_id' => $documentPublicId,
                'version_id' => $versionPublicId,
                'upload_intent_id' => $uploadIntentId,
            ], $now)) {
                $concurrent = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
                if (! $concurrent instanceof stdClass) {
                    throw new UnexpectedValueException('Document idempotency claim could not be resolved.');
                }

                return $this->replayInitiation($actor, $concurrent, $idempotency);
            }

            if ($targetDocument === null) {
                DB::table('documents')->insert([
                    'id' => $documentId,
                    'public_id' => $documentPublicId,
                    'owner_organization_unit_id' => $actor->organizationUnitId,
                    'created_by_user_id' => $actor->principalId,
                    'name' => $request->metadata->name,
                    'description' => $request->metadata->description,
                    'classification' => $request->metadata->classification,
                    'status' => $retention->legalHold
                        ? DocumentStatus::Held->value
                        : DocumentStatus::Draft->value,
                    'current_version_id' => null,
                    'retention_until' => $this->databaseTimestamp($retention->retentionUntil),
                    'retention_policy_key' => $retention->policyKey,
                    'legal_hold' => $retention->legalHold,
                    'legal_hold_reason' => $retention->legalHoldReason,
                    'legal_hold_at' => $retention->legalHold ? $this->databaseTimestamp($now) : null,
                    'lock_version' => 1,
                    'created_at' => $this->databaseTimestamp($now),
                    'updated_at' => $this->databaseTimestamp($now),
                ]);
            }

            // Disk roots own zone prefixes; persisted object keys are root-relative and opaque.
            $objectKey = $storageObjectId.'.blob';
            DB::table('document_storage_objects')->insert([
                'id' => $storageObjectId,
                'disk' => (string) config('documents.storage.quarantine_disk'),
                'object_key' => $objectKey,
                'etag' => null,
                'generation' => null,
                'storage_class' => 'quarantine',
                'immutable' => false,
                'immutable_since' => null,
                'created_at' => $this->databaseTimestamp($now),
                'updated_at' => $this->databaseTimestamp($now),
            ]);
            DB::table('document_versions')->insert([
                'id' => $versionId,
                'public_id' => $versionPublicId,
                'document_id' => $documentId,
                'storage_object_id' => $storageObjectId,
                'version_number' => $targetDocument === null
                    ? 1
                    : ((int) DB::table('document_versions')->where('document_id', $documentId)->max('version_number')) + 1,
                'original_filename' => $request->file->originalFilename,
                'declared_mime_type' => $request->file->declaredMimeType,
                'detected_mime_type' => null,
                'size_bytes' => $request->file->sizeBytes,
                'sha256' => null,
                'scan_status' => DocumentScanStatus::Pending->value,
                'availability_status' => DocumentVersionAvailabilityStatus::Uploading->value,
                'scan_engine_version' => null,
                'scan_result' => null,
                'scanned_at' => null,
                'available_at' => null,
                'promotion_requested_at' => null,
                'created_by_user_id' => $actor->principalId,
                'created_at' => $this->databaseTimestamp($now),
                'updated_at' => $this->databaseTimestamp($now),
            ]);
            DB::table('document_upload_intents')->insert([
                'id' => $uploadIntentId,
                'document_id' => $documentId,
                'document_version_id' => $versionId,
                'storage_object_id' => $storageObjectId,
                'purpose' => $request->purpose,
                'expected_sha256' => $request->file->sha256,
                'expected_size_bytes' => $request->file->sizeBytes,
                'expected_mime_type' => $request->file->declaredMimeType,
                'conditional_create' => true,
                'signed_intent_payload' => null,
                'expires_at' => $this->databaseTimestamp($expiresAt),
                'completed_at' => null,
                'created_at' => $this->databaseTimestamp($now),
                'updated_at' => $this->databaseTimestamp($now),
            ]);
            DB::table('document_quarantines')->insert([
                'id' => UuidV7::generate(),
                'document_version_id' => $versionId,
                'storage_object_id' => $storageObjectId,
                'upload_intent_id' => $uploadIntentId,
                'sha256_verified' => false,
                'size_verified' => false,
                'mime_verified' => false,
                'detected_mime_type' => null,
                'scan_engine' => null,
                'scan_signature_version' => null,
                'scanner_outcome' => null,
                'policy_verdict' => 'quarantined_hard',
                'failure_codes' => null,
                'scanned_at' => null,
                'created_at' => $this->databaseTimestamp($now),
                'updated_at' => $this->databaseTimestamp($now),
            ]);
            if ($targetDocument !== null) {
                DB::table('documents')->where('id', $documentId)->update([
                    'lock_version' => (int) $targetDocument->lock_version + 1,
                    'updated_at' => $this->databaseTimestamp($now),
                ]);
            }

            $uploadIntent = $this->issueSignedIntent(new QuarantineUploadRequest(
                $uploadIntentId,
                $storageObjectId,
                $objectKey,
                $request->file->declaredMimeType,
                $request->file->sizeBytes,
                $request->file->sha256,
                $expiresAt,
            ));
            DB::table('document_upload_intents')->where('id', $uploadIntentId)->update([
                'signed_intent_payload' => Crypt::encryptString(json_encode($uploadIntent->toArray(), JSON_THROW_ON_ERROR)),
                'updated_at' => $this->databaseTimestamp($now),
            ]);
            $this->writeOutbox($documentId, 'com.cluster.documents.uploadinitiated.v1', [
                'document_id' => $documentPublicId,
                'version_id' => $versionPublicId,
                'upload_intent_id' => $uploadIntentId,
            ], $now);

            return new InitiatedDocumentUpload(
                $documentPublicId,
                $versionPublicId,
                $storageObjectId,
                $request->purpose,
                (int) config('documents.uploads.max_size_bytes'),
                $uploadIntent,
            );
        });
    }

    public function complete(
        AuthorizedDocumentActor $actor,
        string $uploadIntentId,
        CompleteDocumentUpload $completion,
        IdempotencyContext $idempotency,
        ?int $expectedDocumentLockVersion = null,
    ): DocumentUploadCompletion {
        UuidV7::assert($uploadIntentId, 'Upload intent id');
        $upload = $this->requiredUploadIntent($uploadIntentId);
        $this->assertActor($actor, self::COMPLETE_OPERATION, (string) $upload->owner_organization_unit_id, $idempotency);
        $existing = $this->idempotencyQuery($idempotency)->first();
        if ($existing instanceof stdClass) {
            return $this->replayCompletion($existing, $idempotency, (string) $upload->version_public_id);
        }
        $this->assertCompletable($upload);

        try {
            $stored = $this->storage->inspectQuarantineObject($this->reference($upload));
        } catch (RetryableStorageException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DomainException('quarantine_object_unavailable', previous: $exception);
        }
        $file = new UploadFileMetadata(
            (string) $upload->original_filename,
            (int) $upload->expected_size_bytes,
            (string) $upload->expected_mime_type,
            (string) $upload->expected_sha256,
        );
        $failureCodes = $this->uploadPolicy->completionFailureCodes($file, $stored, $completion);

        return DB::transaction(function () use ($actor, $uploadIntentId, $idempotency, $stored, $failureCodes, $expectedDocumentLockVersion, $upload): DocumentUploadCompletion {
            if ($expectedDocumentLockVersion !== null) {
                $matched = DB::table('documents')
                    ->where('id', $upload->document_id)
                    ->where('lock_version', $expectedDocumentLockVersion)
                    ->update(['lock_version' => $expectedDocumentLockVersion]);
                if ($matched !== 1) {
                    throw new StaleDocumentLockVersion((string) $upload->document_public_id, $expectedDocumentLockVersion);
                }
            }
            $upload = $this->requiredUploadIntent($uploadIntentId, true);
            $this->assertActor($actor, self::COMPLETE_OPERATION, (string) $upload->owner_organization_unit_id, $idempotency);
            $existing = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
            if ($existing instanceof stdClass) {
                return $this->replayCompletion($existing, $idempotency, (string) $upload->version_public_id);
            }
            $this->assertCompletable($upload);
            $now = $this->now();
            if (! $this->claimIdempotency($idempotency, 'document_version', (string) $upload->version_public_id, [], $now)) {
                $concurrent = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
                if (! $concurrent instanceof stdClass) {
                    throw new UnexpectedValueException('Document idempotency claim could not be resolved.');
                }

                return $this->replayCompletion($concurrent, $idempotency, (string) $upload->version_public_id);
            }

            $accepted = $failureCodes === [];
            $scanStatus = $accepted ? DocumentScanStatus::Pending : DocumentScanStatus::Failed;
            $availabilityStatus = DocumentVersionAvailabilityStatus::Quarantined;
            DB::table('document_storage_objects')->where('id', $upload->storage_object_id)->update([
                'etag' => $stored->etag,
                'generation' => $stored->generation,
                'updated_at' => $this->databaseTimestamp($now),
            ]);
            DB::table('document_versions')->where('id', $upload->version_id)->update([
                'detected_mime_type' => $stored->detectedMimeType,
                'size_bytes' => $stored->sizeBytes,
                'sha256' => $stored->sha256,
                'scan_status' => $scanStatus->value,
                'availability_status' => $availabilityStatus->value,
                'updated_at' => $this->databaseTimestamp($now),
            ]);
            DB::table('document_upload_intents')->where('id', $uploadIntentId)->update([
                'completed_at' => $this->databaseTimestamp($now),
                'updated_at' => $this->databaseTimestamp($now),
            ]);
            DB::table('document_quarantines')->where('document_version_id', $upload->version_id)->update([
                'sha256_verified' => ! in_array('sha256_mismatch', $failureCodes, true),
                'size_verified' => ! in_array('size_mismatch', $failureCodes, true),
                'mime_verified' => ! in_array('mime_extension_mismatch', $failureCodes, true),
                'detected_mime_type' => $stored->detectedMimeType,
                'policy_verdict' => $accepted ? 'quarantined_hard' : 'blocked',
                'failure_codes' => $failureCodes === [] ? null : json_encode($failureCodes, JSON_THROW_ON_ERROR),
                'updated_at' => $this->databaseTimestamp($now),
            ]);
            $result = new DocumentUploadCompletion(
                $accepted,
                (string) $upload->document_public_id,
                (string) $upload->version_public_id,
                $scanStatus->value,
                $availabilityStatus->value,
                $failureCodes,
            );
            $this->storeIdempotencyResponse($idempotency, 'document_version', (string) $upload->version_public_id, $result->toArray(), $now);
            $this->writeOutbox((string) $upload->document_id, $accepted
                ? 'com.cluster.documents.versionuploaded.v1'
                : 'com.cluster.documents.versionrejected.v1', [
                    'document_id' => $upload->document_public_id,
                    'version_id' => $upload->version_public_id,
                    'availability_status' => $availabilityStatus->value,
                ], $now);

            return $result;
        });
    }

    public function scanVersion(
        AuthorizedDocumentActor $actor,
        string $versionPublicId,
        IdempotencyContext $idempotency,
    ): DocumentScanResult {
        UuidV7::assert($versionPublicId, 'Document version id');
        $version = $this->requiredVersion($versionPublicId);
        $this->assertActor($actor, self::SCAN_OPERATION, (string) $version->owner_organization_unit_id, $idempotency);
        $existing = $this->idempotencyQuery($idempotency)->first();
        if ($existing instanceof stdClass) {
            return $this->replayScan($existing, $idempotency, $versionPublicId);
        }
        $this->assertScannable($version);

        try {
            $stored = $this->storage->inspectQuarantineObject($this->reference($version));
        } catch (RetryableStorageException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DomainException('quarantine_object_unavailable', previous: $exception);
        }
        $bound = $this->boundProperties($version);
        $scan = $bound->matches($stored)
            ? $this->scanVerifiedObject($version, $stored)
            : MalwareScanResult::blocked('integrity', 'quarantine_binding_mismatch');

        return DB::transaction(function () use ($actor, $versionPublicId, $idempotency, $scan): DocumentScanResult {
            $existing = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
            if ($existing instanceof stdClass) {
                return $this->replayScan($existing, $idempotency, $versionPublicId);
            }
            $version = $this->requiredVersion($versionPublicId, true);
            $this->assertActor($actor, self::SCAN_OPERATION, (string) $version->owner_organization_unit_id, $idempotency);
            $this->assertScannable($version);
            $now = $this->now();
            if (! $this->claimIdempotency($idempotency, 'document_version', (string) $version->version_public_id, [], $now)) {
                $concurrent = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
                if (! $concurrent instanceof stdClass) {
                    throw new UnexpectedValueException('Document idempotency claim could not be resolved.');
                }

                return $this->replayScan($concurrent, $idempotency, (string) $version->version_public_id);
            }

            [$scanStatus, $availabilityStatus, $verdict] = $this->scanTransition($scan);
            DB::table('document_versions')->where('id', $version->version_id)->update([
                'scan_status' => $scanStatus->value,
                'availability_status' => $availabilityStatus->value,
                'scan_engine_version' => $scan->signatureVersion,
                'scan_result' => json_encode(['outcome' => $scan->outcome, 'reason_code' => $scan->reasonCode], JSON_THROW_ON_ERROR),
                'scanned_at' => $this->databaseTimestamp($now),
                'promotion_requested_at' => $availabilityStatus === DocumentVersionAvailabilityStatus::PromotionPending
                    ? $this->databaseTimestamp($now)
                    : null,
                'updated_at' => $this->databaseTimestamp($now),
            ]);
            DB::table('document_quarantines')->where('document_version_id', $version->version_id)->update([
                'scan_engine' => $scan->engine,
                'scan_signature_version' => $scan->signatureVersion,
                'scanner_outcome' => $scan->outcome,
                'policy_verdict' => $verdict,
                'failure_codes' => $scan->reasonCode === null ? null : json_encode([$scan->reasonCode], JSON_THROW_ON_ERROR),
                'scanned_at' => $this->databaseTimestamp($now),
                'updated_at' => $this->databaseTimestamp($now),
            ]);
            if ($availabilityStatus === DocumentVersionAvailabilityStatus::Rejected
                && $version->current_version_id === null
                && ! $version->legal_hold
                && $version->document_status !== DocumentStatus::Held->value) {
                DB::table('documents')->where('id', $version->document_id)->update([
                    'status' => DocumentStatus::Rejected->value,
                    'updated_at' => $this->databaseTimestamp($now),
                ]);
            }

            $result = new DocumentScanResult(
                (string) $version->document_public_id,
                (string) $version->version_public_id,
                $scanStatus->value,
                $availabilityStatus->value,
            );
            $this->storeIdempotencyResponse($idempotency, 'document_version', (string) $version->version_public_id, $result->toArray(), $now);
            $this->writeOutbox((string) $version->document_id, match ($availabilityStatus) {
                DocumentVersionAvailabilityStatus::PromotionPending => 'com.cluster.documents.versionpromotionrequested.v1',
                DocumentVersionAvailabilityStatus::Rejected => 'com.cluster.documents.versionrejected.v1',
                default => 'com.cluster.documents.versionquarantined.v1',
            }, [
                'document_id' => $version->document_public_id,
                'version_id' => $version->version_public_id,
                'availability_status' => $availabilityStatus->value,
            ], $now);

            return $result;
        });
    }

    /**
     * Post-commit reconciliation. Promotion is idempotent at the storage boundary,
     * so a database failure after a successful copy remains safely retryable.
     */
    public function reconcilePromotion(
        AuthorizedDocumentActor $actor,
        string $versionPublicId,
        IdempotencyContext $idempotency,
    ): DocumentScanResult {
        UuidV7::assert($versionPublicId, 'Document version id');
        $version = $this->requiredVersion($versionPublicId);
        $this->assertActor($actor, self::RECONCILE_PROMOTION_OPERATION, (string) $version->owner_organization_unit_id, $idempotency);
        $existing = $this->idempotencyQuery($idempotency)->first();
        if ($existing instanceof stdClass) {
            return $this->replayScan($existing, $idempotency, $versionPublicId);
        }
        if ($version->scan_status !== DocumentScanStatus::Clean->value
            || $version->availability_status !== DocumentVersionAvailabilityStatus::PromotionPending->value) {
            throw new DomainException('document_promotion_invalid_state');
        }

        try {
            $promoted = $this->storage->promoteVerifiedObject(new VerifiedQuarantineObject(
                $this->reference($version),
                $this->boundProperties($version),
            ));
        } catch (RetryableStorageException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DomainException('document_promotion_unavailable', previous: $exception);
        }
        $expected = $this->boundProperties($version);
        if (! $expected->matches($promoted)) {
            throw new DomainException('document_promotion_integrity_mismatch');
        }

        return DB::transaction(function () use ($actor, $versionPublicId, $idempotency, $promoted): DocumentScanResult {
            $existing = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
            if ($existing instanceof stdClass) {
                return $this->replayScan($existing, $idempotency, $versionPublicId);
            }
            $version = $this->requiredVersion($versionPublicId, true);
            $this->assertActor($actor, self::RECONCILE_PROMOTION_OPERATION, (string) $version->owner_organization_unit_id, $idempotency);
            if ($version->scan_status !== DocumentScanStatus::Clean->value
                || $version->availability_status !== DocumentVersionAvailabilityStatus::PromotionPending->value) {
                throw new DomainException('document_promotion_invalid_state');
            }
            $now = $this->now();
            if (! $this->claimIdempotency($idempotency, 'document_version', (string) $version->version_public_id, [], $now)) {
                $concurrent = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
                if (! $concurrent instanceof stdClass) {
                    throw new UnexpectedValueException('Document idempotency claim could not be resolved.');
                }

                return $this->replayScan($concurrent, $idempotency, (string) $version->version_public_id);
            }
            DB::table('document_storage_objects')->where('id', $version->storage_object_id)->update([
                'disk' => (string) config('documents.storage.available_disk'),
                'etag' => $promoted->etag,
                'generation' => $promoted->generation,
                'storage_class' => 'available',
                'immutable' => true,
                'immutable_since' => $this->databaseTimestamp($now),
                'updated_at' => $this->databaseTimestamp($now),
            ]);
            DB::table('document_versions')->where('id', $version->version_id)->update([
                'availability_status' => DocumentVersionAvailabilityStatus::Available->value,
                'available_at' => $this->databaseTimestamp($now),
                'updated_at' => $this->databaseTimestamp($now),
            ]);
            DB::table('documents')->where('id', $version->document_id)->update([
                'status' => $version->legal_hold || $version->document_status === DocumentStatus::Held->value
                    ? DocumentStatus::Held->value
                    : DocumentStatus::Active->value,
                'current_version_id' => $version->version_id,
                'updated_at' => $this->databaseTimestamp($now),
            ]);
            $result = new DocumentScanResult(
                (string) $version->document_public_id,
                (string) $version->version_public_id,
                DocumentScanStatus::Clean->value,
                DocumentVersionAvailabilityStatus::Available->value,
            );
            $this->storeIdempotencyResponse($idempotency, 'document_version', (string) $version->version_public_id, $result->toArray(), $now);
            $this->writeOutbox((string) $version->document_id, 'com.cluster.documents.versionavailable.v1', [
                'document_id' => $version->document_public_id,
                'version_id' => $version->version_public_id,
                'availability_status' => DocumentVersionAvailabilityStatus::Available->value,
            ], $now);

            return $result;
        });
    }

    private function scanVerifiedObject(stdClass $version, StoredObjectProperties $stored): MalwareScanResult
    {
        try {
            return $this->scanner->scan(new VerifiedQuarantineObject($this->reference($version), $stored));
        } catch (Throwable) {
            return MalwareScanResult::unavailable('scanner', 'scanner_unavailable');
        }
    }

    /** @return array{DocumentScanStatus, DocumentVersionAvailabilityStatus, string} */
    private function scanTransition(MalwareScanResult $scan): array
    {
        return match ($scan->outcome) {
            'clean' => [DocumentScanStatus::Clean, DocumentVersionAvailabilityStatus::PromotionPending, 'promotion_pending'],
            'infected', 'blocked' => [DocumentScanStatus::Infected, DocumentVersionAvailabilityStatus::Rejected, 'blocked'],
            default => [DocumentScanStatus::Failed, DocumentVersionAvailabilityStatus::Quarantined, 'quarantined_hard'],
        };
    }

    private function replayInitiation(AuthorizedDocumentActor $actor, stdClass $entry, IdempotencyContext $idempotency): InitiatedDocumentUpload
    {
        if (! is_string($entry->resource_id)) {
            throw new DomainException('idempotency_resource_mismatch');
        }
        $payload = $this->replayPayload($entry, $idempotency, 'upload_intent', $entry->resource_id);
        $uploadIntentId = $payload['upload_intent_id'] ?? null;
        if (! is_string($uploadIntentId) || ! hash_equals($entry->resource_id, $uploadIntentId)) {
            throw new UnexpectedValueException('Stored document upload idempotency state is incomplete.');
        }
        $upload = $this->requiredUploadIntent($uploadIntentId);
        if (! hash_equals($entry->resource_id, (string) $upload->upload_intent_id)) {
            throw new DomainException('idempotency_resource_mismatch');
        }
        $this->assertActor($actor, self::INITIATE_OPERATION, (string) $upload->owner_organization_unit_id, $idempotency);
        if ($upload->completed_at !== null) {
            throw new DomainException('upload_intent_already_consumed');
        }

        return new InitiatedDocumentUpload(
            (string) $upload->document_public_id,
            (string) $upload->version_public_id,
            (string) $upload->storage_object_id,
            (string) $upload->purpose,
            (int) config('documents.uploads.max_size_bytes'),
            $this->storedSignedIntent($upload),
        );
    }

    private function replayCompletion(stdClass $entry, IdempotencyContext $idempotency, string $versionPublicId): DocumentUploadCompletion
    {
        $payload = $this->replayPayload($entry, $idempotency, 'document_version', $versionPublicId);

        return new DocumentUploadCompletion(
            (bool) ($payload['accepted'] ?? false),
            $this->requiredString($payload, 'document_id'),
            $this->requiredString($payload, 'version_id'),
            $this->requiredString($payload, 'scan_status'),
            $this->requiredString($payload, 'availability_status'),
            is_array($payload['failure_codes'] ?? null) ? $payload['failure_codes'] : [],
        );
    }

    private function replayScan(stdClass $entry, IdempotencyContext $idempotency, string $versionPublicId): DocumentScanResult
    {
        $payload = $this->replayPayload($entry, $idempotency, 'document_version', $versionPublicId);

        return new DocumentScanResult(
            $this->requiredString($payload, 'document_id'),
            $this->requiredString($payload, 'version_id'),
            $this->requiredString($payload, 'scan_status'),
            $this->requiredString($payload, 'availability_status'),
        );
    }

    private function issueSignedIntent(QuarantineUploadRequest $request): SignedUploadIntent
    {
        if ($request->expiresAt <= $this->now()) {
            throw new DomainException('upload_intent_expired');
        }
        $intent = $this->storage->issueQuarantineUpload($request);
        $required = [
            'content-length' => (string) $request->expectedSizeBytes,
            'content-type' => $request->declaredMimeType,
            'x-amz-checksum-sha256' => base64_encode(hex2bin($request->expectedSha256)),
            'if-none-match' => '*',
        ];
        $signedHeaders = array_change_key_case($intent->requiredHeaders, CASE_LOWER);
        foreach ($required as $header => $value) {
            if (! array_key_exists($header, $signedHeaders) || $signedHeaders[$header] !== $value) {
                throw new UnexpectedValueException('Signed upload intent does not bind the required object condition.');
            }
        }
        if ($intent->expiresAt > $request->expiresAt) {
            throw new UnexpectedValueException('Signed upload URL expiry exceeds the database upload intent expiry.');
        }
        $host = parse_url($intent->url, PHP_URL_HOST);
        $allowlist = config('documents.storage.upload_endpoint_allowlist');
        if (! is_string($host)
            || ! is_array($allowlist)
            || ! in_array(strtolower($host), $allowlist, true)) {
            throw new UnexpectedValueException('Signed upload endpoint is not allowlisted.');
        }

        return $intent;
    }

    private function storedSignedIntent(stdClass $upload): SignedUploadIntent
    {
        if (! is_string($upload->signed_intent_payload)) {
            throw new UnexpectedValueException('Stored signed upload intent is unavailable.');
        }
        try {
            $payload = json_decode(Crypt::decryptString($upload->signed_intent_payload), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new UnexpectedValueException('Stored signed upload intent is invalid.');
        }
        if (! is_array($payload)) {
            throw new UnexpectedValueException('Stored signed upload intent is invalid.');
        }

        return SignedUploadIntent::fromArray($payload);
    }

    private function requiredUploadIntent(string $uploadIntentId, bool $lockForUpdate = false): stdClass
    {
        $query = DB::table('document_upload_intents as intents')
            ->join('documents as documents', 'documents.id', '=', 'intents.document_id')
            ->join('document_versions as versions', 'versions.id', '=', 'intents.document_version_id')
            ->join('document_storage_objects as objects', 'objects.id', '=', 'intents.storage_object_id')
            ->where('intents.id', $uploadIntentId)
            ->select([
                'intents.id as upload_intent_id', 'intents.completed_at', 'intents.expires_at',
                'intents.purpose', 'intents.expected_sha256', 'intents.expected_size_bytes', 'intents.expected_mime_type', 'intents.conditional_create', 'intents.signed_intent_payload',
                'documents.id as document_id', 'documents.public_id as document_public_id', 'documents.owner_organization_unit_id',
                'versions.id as version_id', 'versions.public_id as version_public_id', 'versions.original_filename',
                'versions.availability_status', 'objects.id as storage_object_id', 'objects.object_key',
            ]);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if (! $row instanceof stdClass) {
            throw new DomainException('upload_intent_not_found');
        }

        return $row;
    }

    private function requiredVersion(string $versionPublicId, bool $lockForUpdate = false): stdClass
    {
        $query = DB::table('document_versions as versions')
            ->join('documents as documents', 'documents.id', '=', 'versions.document_id')
            ->join('document_storage_objects as objects', 'objects.id', '=', 'versions.storage_object_id')
            ->join('document_quarantines as quarantines', 'quarantines.document_version_id', '=', 'versions.id')
            ->where('versions.public_id', $versionPublicId)
            ->select([
                'versions.id as version_id', 'versions.public_id as version_public_id', 'versions.document_id',
                'versions.storage_object_id', 'versions.sha256', 'versions.size_bytes', 'versions.detected_mime_type',
                'versions.scan_status', 'versions.availability_status', 'versions.promotion_requested_at',
                'documents.public_id as document_public_id', 'documents.owner_organization_unit_id', 'documents.status as document_status', 'documents.legal_hold', 'documents.current_version_id',
                'objects.etag as storage_etag', 'objects.generation as storage_generation',
                'quarantines.policy_verdict as quarantine_policy_verdict',
            ]);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if (! $row instanceof stdClass) {
            throw new DomainException('document_version_not_found');
        }

        return $row;
    }

    private function assertCompletable(stdClass $upload): void
    {
        if ($upload->completed_at !== null
            || $upload->availability_status !== DocumentVersionAvailabilityStatus::Uploading->value
            || ! $upload->conditional_create
            || $this->dateTime((string) $upload->expires_at) <= $this->now()) {
            throw new DomainException('upload_completion_invalid_state');
        }
    }

    private function assertScannable(stdClass $version): void
    {
        if ($version->availability_status !== DocumentVersionAvailabilityStatus::Quarantined->value
            || ($version->scan_status !== DocumentScanStatus::Pending->value
                && ($version->scan_status !== DocumentScanStatus::Failed->value
                    || $version->quarantine_policy_verdict !== 'quarantined_hard'))) {
            throw new DomainException('document_scan_invalid_state');
        }
    }

    private function assertActor(
        AuthorizedDocumentActor $actor,
        string $operation,
        string $ownerOrganizationUnitId,
        IdempotencyContext $idempotency,
    ): void {
        $actor->assertBoundTo($operation, $ownerOrganizationUnitId);
        if (! hash_equals($actor->principalId, $idempotency->principalId)) {
            throw new DomainException('document_idempotency_actor_mismatch');
        }
        if ($idempotency->operation !== $operation) {
            throw new DomainException('idempotency_operation_invalid');
        }
    }

    private function reference(stdClass $row): QuarantineObjectReference
    {
        return new QuarantineObjectReference((string) $row->storage_object_id);
    }

    private function boundProperties(stdClass $version): StoredObjectProperties
    {
        if (! is_string($version->sha256)
            || ! is_string($version->detected_mime_type)
            || ! is_string($version->storage_etag)
            || ! is_string($version->storage_generation)) {
            throw new DomainException('quarantine_binding_unavailable');
        }

        return new StoredObjectProperties(
            $version->sha256,
            (int) $version->size_bytes,
            $version->detected_mime_type,
            $version->storage_etag,
            $version->storage_generation,
        );
    }

    private function idempotencyQuery(IdempotencyContext $idempotency): mixed
    {
        return DB::table('document_idempotency_keys')
            ->where('principal_id', $idempotency->principalId)
            ->where('operation', $idempotency->operation)
            ->where('idempotency_key_hash', $idempotency->keyHash);
    }

    /** @param array<string, mixed> $response */
    private function claimIdempotency(IdempotencyContext $idempotency, string $resourceType, string $resourceId, array $response, DateTimeImmutable $now): bool
    {
        $claimed = DB::table('document_idempotency_keys')->insertOrIgnore([
            'id' => UuidV7::generate(), 'principal_id' => $idempotency->principalId, 'operation' => $idempotency->operation,
            'idempotency_key_hash' => $idempotency->keyHash, 'request_hash' => $idempotency->requestHash,
            'resource_type' => $resourceType, 'resource_id' => $resourceId,
            'response_payload' => json_encode($response, JSON_THROW_ON_ERROR),
            'created_at' => $this->databaseTimestamp($now), 'updated_at' => $this->databaseTimestamp($now),
        ]);
        if ($claimed) {
            return true;
        }
        $existing = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
        if (! $existing instanceof stdClass) {
            throw new UnexpectedValueException('Document idempotency claim could not be resolved.');
        }
        $this->replayPayload($existing, $idempotency, $resourceType, $resourceId);

        return false;
    }

    /** @param array<string, mixed> $response */
    private function storeIdempotencyResponse(
        IdempotencyContext $idempotency,
        string $resourceType,
        string $resourceId,
        array $response,
        DateTimeImmutable $now,
    ): void {
        if ($this->idempotencyQuery($idempotency)
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->update([
                'response_payload' => json_encode($response, JSON_THROW_ON_ERROR),
                'updated_at' => $this->databaseTimestamp($now),
            ]) !== 1) {
            throw new UnexpectedValueException('Document idempotency response could not be stored.');
        }
    }

    /** @return array<string, mixed> */
    private function replayPayload(
        stdClass $entry,
        IdempotencyContext $idempotency,
        string $resourceType,
        string $resourceId,
    ): array {
        if (! is_string($entry->request_hash) || ! hash_equals($entry->request_hash, $idempotency->requestHash)) {
            throw new DomainException('idempotency_request_mismatch');
        }
        if (! is_string($entry->resource_type)
            || ! is_string($entry->resource_id)
            || ! hash_equals($entry->resource_type, $resourceType)
            || ! hash_equals($entry->resource_id, $resourceId)) {
            throw new DomainException('idempotency_resource_mismatch');
        }
        if (! is_string($entry->response_payload)) {
            throw new UnexpectedValueException('Stored document idempotency state is incomplete.');
        }
        try {
            $payload = json_decode($entry->response_payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException('Stored document idempotency response is invalid.');
        }
        if (! is_array($payload)) {
            throw new UnexpectedValueException('Stored document idempotency response is invalid.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key): string
    {
        if (! is_string($payload[$key] ?? null)) {
            throw new UnexpectedValueException('Stored document idempotency response is incomplete.');
        }

        return $payload[$key];
    }

    /** @param array<string, mixed> $payload */
    private function writeOutbox(string $documentId, string $type, array $payload, DateTimeImmutable $now): void
    {
        DB::table('document_outbox_events')->insert([
            'id' => UuidV7::generate(), 'aggregate_id' => $documentId, 'event_type' => $type,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'occurred_at' => $this->databaseTimestamp($now),
            'published_at' => null, 'created_at' => $this->databaseTimestamp($now), 'updated_at' => $this->databaseTimestamp($now),
        ]);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function dateTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function databaseTimestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function uploadIntentTtlSeconds(): int
    {
        $ttl = config('documents.storage.upload_intent_ttl_seconds');
        if (! is_int($ttl) || $ttl < 1 || $ttl > 300) {
            throw new UnexpectedValueException('Document upload intent TTL is invalid.');
        }

        return $ttl;
    }
}
