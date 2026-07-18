<?php

namespace Modules\Documents\Application;

use InvalidArgumentException;

final readonly class UploadFileMetadata
{
    public string $extension;

    public function __construct(
        public string $originalFilename,
        public int $sizeBytes,
        public string $declaredMimeType,
        public string $sha256,
    ) {
        if (trim($this->originalFilename) === ''
            || mb_strlen($this->originalFilename) > 255
            || preg_match('/[\\\\\/\x00-\x1F]/', $this->originalFilename) === 1) {
            throw new InvalidArgumentException('Original filename is invalid.');
        }
        if ($this->sizeBytes < 1) {
            throw new InvalidArgumentException('Document size must be positive.');
        }
        if (preg_match('/\A[a-f0-9]{64}\z/', $this->sha256) !== 1) {
            throw new InvalidArgumentException('Document SHA-256 must be lowercase hexadecimal.');
        }
        if (preg_match('/\A[a-z0-9][a-z0-9.+-]*\/[a-z0-9][a-z0-9.+-]*\z/', $this->declaredMimeType) !== 1) {
            throw new InvalidArgumentException('Declared MIME type is invalid.');
        }

        $extension = strtolower(pathinfo($this->originalFilename, PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new InvalidArgumentException('Original filename must have an extension.');
        }
        $this->extension = $extension;
    }
}
