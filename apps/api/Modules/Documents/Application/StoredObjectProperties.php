<?php

namespace Modules\Documents\Application;

use InvalidArgumentException;

final readonly class StoredObjectProperties
{
    public function __construct(
        public string $sha256,
        public int $sizeBytes,
        public string $detectedMimeType,
        public string $etag,
        public string $generation,
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/', $this->sha256) !== 1) {
            throw new InvalidArgumentException('Stored object SHA-256 must be lowercase hexadecimal.');
        }
        if ($this->sizeBytes < 1) {
            throw new InvalidArgumentException('Stored object size must be positive.');
        }
        if (preg_match('/\A[a-z0-9][a-z0-9.+-]*\/[a-z0-9][a-z0-9.+-]*\z/', $this->detectedMimeType) !== 1) {
            throw new InvalidArgumentException('Detected MIME type is invalid.');
        }
        if (trim($this->etag) === '' || mb_strlen($this->etag) > 512) {
            throw new InvalidArgumentException('Storage ETag is invalid.');
        }
        if (trim($this->generation) === '' || mb_strlen($this->generation) > 128) {
            throw new InvalidArgumentException('Storage generation is invalid.');
        }
    }

    public function matches(StoredObjectProperties $other): bool
    {
        return hash_equals($this->sha256, $other->sha256)
            && $this->sizeBytes === $other->sizeBytes
            && hash_equals($this->detectedMimeType, $other->detectedMimeType)
            && hash_equals($this->etag, $other->etag)
            && hash_equals($this->generation, $other->generation);
    }
}
