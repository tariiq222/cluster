<?php

namespace Modules\Documents\Tests\Support;

use DomainException;
use Modules\Documents\Application\QuarantineObjectReference;
use Modules\Documents\Application\QuarantineUploadRequest;
use Modules\Documents\Application\RetryableStorageException;
use Modules\Documents\Application\SignedUploadIntent;
use Modules\Documents\Application\StoredObjectProperties;
use Modules\Documents\Application\VerifiedQuarantineObject;
use Modules\Documents\Contracts\PrivateObjectStorage;

final class InMemoryPrivateObjectStorage implements PrivateObjectStorage
{
    /** @var array<string, array{storage_object_id: string, request: QuarantineUploadRequest, properties: StoredObjectProperties|null, promoted: bool}> */
    private array $uploads = [];

    public int $promotionCalls = 0;

    public int $issuedIntentCalls = 0;

    public bool $returnMalformedIntent = false;

    public bool $returnCanonicalLowercaseIntent = false;

    public int $intentExpiryOffsetSeconds = 0;

    public ?string $intentUrlOverride = null;

    public ?StoredObjectProperties $postPromotionPropertiesOverride = null;

    private bool $failNextInspectionRetryably = false;

    private bool $failNextPromotionRetryably = false;

    public function issueQuarantineUpload(QuarantineUploadRequest $request): SignedUploadIntent
    {
        $this->issuedIntentCalls++;
        if (! isset($this->uploads[$request->uploadIntentId])) {
            $this->uploads[$request->uploadIntentId] = [
                'storage_object_id' => $request->storageObjectId,
                'request' => $request,
                'properties' => null,
                'promoted' => false,
            ];
        }

        return new SignedUploadIntent(
            $request->uploadIntentId,
            $this->intentUrlOverride ?? 'https://storage.invalid/upload-intents/'.$request->uploadIntentId,
            'PUT',
            $request->expiresAt->modify(($this->intentExpiryOffsetSeconds >= 0 ? '+' : '').$this->intentExpiryOffsetSeconds.' seconds'),
            $this->returnMalformedIntent
                ? ['Content-Type' => $request->declaredMimeType]
                : ($this->returnCanonicalLowercaseIntent ? [
                    'content-length' => (string) $request->expectedSizeBytes,
                    'content-type' => $request->declaredMimeType,
                    'x-amz-checksum-sha256' => base64_encode(hex2bin($request->expectedSha256)),
                    'if-none-match' => '*',
                ] : [
                    'Content-Length' => (string) $request->expectedSizeBytes,
                    'Content-Type' => $request->declaredMimeType,
                    'x-amz-checksum-sha256' => base64_encode(hex2bin($request->expectedSha256)),
                    'If-None-Match' => '*',
                ]),
        );
    }

    public function completeUpload(string $uploadIntentId, StoredObjectProperties $properties): void
    {
        if (! isset($this->uploads[$uploadIntentId])) {
            throw new DomainException('upload_intent_not_issued');
        }

        if ($this->uploads[$uploadIntentId]['properties'] instanceof StoredObjectProperties) {
            throw new DomainException('conditional_create_failed');
        }

        $this->uploads[$uploadIntentId]['properties'] = $properties;
    }

    public function replaceUpload(string $uploadIntentId, StoredObjectProperties $properties): void
    {
        if (! isset($this->uploads[$uploadIntentId])) {
            throw new DomainException('upload_intent_not_issued');
        }

        $this->uploads[$uploadIntentId]['properties'] = $properties;
    }

    public function failNextInspectionRetryably(): void
    {
        $this->failNextInspectionRetryably = true;
    }

    public function failNextPromotionRetryably(): void
    {
        $this->failNextPromotionRetryably = true;
    }

    public function inspectQuarantineObject(QuarantineObjectReference $reference): StoredObjectProperties
    {
        if ($this->failNextInspectionRetryably) {
            $this->failNextInspectionRetryably = false;

            throw new RetryableStorageException('storage_inspection_retryable');
        }
        foreach ($this->uploads as $upload) {
            if ($upload['storage_object_id'] === $reference->storageObjectId && $upload['properties'] instanceof StoredObjectProperties) {
                return $upload['properties'];
            }
        }

        throw new DomainException('quarantine_object_unavailable');
    }

    public function promoteVerifiedObject(VerifiedQuarantineObject $object): StoredObjectProperties
    {
        if ($this->failNextPromotionRetryably) {
            $this->failNextPromotionRetryably = false;

            throw new RetryableStorageException('storage_promotion_retryable');
        }
        foreach ($this->uploads as $uploadIntentId => $upload) {
            if ($upload['storage_object_id'] !== $object->reference->storageObjectId
                || ! $upload['properties'] instanceof StoredObjectProperties) {
                continue;
            }
            if (! $upload['properties']->matches($object->properties)) {
                throw new DomainException('storage_generation_precondition_failed');
            }
            if (! $upload['promoted']) {
                $this->uploads[$uploadIntentId]['promoted'] = true;
                $this->promotionCalls++;
            }

            return $this->postPromotionPropertiesOverride ?? $upload['properties'];
        }

        throw new DomainException('quarantine_object_unavailable');
    }
}
