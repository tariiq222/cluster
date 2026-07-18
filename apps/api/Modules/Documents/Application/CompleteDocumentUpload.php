<?php

namespace Modules\Documents\Application;

use InvalidArgumentException;

final readonly class CompleteDocumentUpload
{
    public function __construct(
        public string $sha256,
        public int $sizeBytes,
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/', $this->sha256) !== 1) {
            throw new InvalidArgumentException('Document SHA-256 must be lowercase hexadecimal.');
        }
        if ($this->sizeBytes < 1) {
            throw new InvalidArgumentException('Completed document size must be positive.');
        }
    }
}
