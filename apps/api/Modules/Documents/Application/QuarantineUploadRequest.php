<?php

namespace Modules\Documents\Application;

use DateTimeImmutable;
use Modules\Documents\Domain\UuidV7;

final readonly class QuarantineUploadRequest
{
    public function __construct(
        public string $uploadIntentId,
        public string $storageObjectId,
        private string $objectKey,
        public string $declaredMimeType,
        public int $expectedSizeBytes,
        public string $expectedSha256,
        public DateTimeImmutable $expiresAt,
    ) {
        UuidV7::assert($this->uploadIntentId, 'Upload intent id');
        UuidV7::assert($this->storageObjectId, 'Storage object id');
        if (preg_match('/\A[a-f0-9]{64}\z/', $this->expectedSha256) !== 1) {
            throw new \InvalidArgumentException('Expected document SHA-256 must be lowercase hexadecimal.');
        }
    }

    /** Internal adapter input only; never serialize this value for callers. */
    public function objectKey(): string
    {
        return $this->objectKey;
    }
}
