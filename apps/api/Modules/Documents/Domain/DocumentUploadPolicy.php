<?php

namespace Modules\Documents\Domain;

use InvalidArgumentException;
use Modules\Documents\Application\CompleteDocumentUpload;
use Modules\Documents\Application\StoredObjectProperties;
use Modules\Documents\Application\UploadFileMetadata;

final readonly class DocumentUploadPolicy
{
    /** @param array<string, list<string>> $allowedMimeTypes */
    public function __construct(
        private int $maxSizeBytes,
        private array $allowedMimeTypes,
    ) {
        if ($this->maxSizeBytes < 1) {
            throw new InvalidArgumentException('Document upload maximum size must be positive.');
        }
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self
    {
        $uploads = $config['uploads'] ?? null;
        if (! is_array($uploads)
            || ! is_int($uploads['max_size_bytes'] ?? null)
            || ! is_array($uploads['allowed_mime_types'] ?? null)) {
            throw new InvalidArgumentException('Document upload configuration is invalid.');
        }

        /** @var array<string, list<string>> $mimeTypes */
        $mimeTypes = $uploads['allowed_mime_types'];

        return new self($uploads['max_size_bytes'], $mimeTypes);
    }

    public function assertCanInitiate(UploadFileMetadata $file): void
    {
        if ($file->sizeBytes > $this->maxSizeBytes) {
            throw new InvalidArgumentException('Document exceeds the configured upload size limit.');
        }

        $allowedMimeTypes = $this->allowedFor($file->extension);
        if (! in_array($file->declaredMimeType, $allowedMimeTypes, true)) {
            throw new InvalidArgumentException('Declared MIME type is not allowed for the filename extension.');
        }
    }

    /** @return list<string> */
    public function completionFailureCodes(
        UploadFileMetadata $file,
        StoredObjectProperties $stored,
        CompleteDocumentUpload $completion,
    ): array {
        $failures = [];
        if (! hash_equals($file->sha256, $stored->sha256)
            || ! hash_equals($file->sha256, $completion->sha256)) {
            $failures[] = 'sha256_mismatch';
        }
        if ($stored->sizeBytes !== $file->sizeBytes || $completion->sizeBytes !== $file->sizeBytes) {
            $failures[] = 'size_mismatch';
        }
        if (! in_array($stored->detectedMimeType, $this->allowedFor($file->extension), true)) {
            $failures[] = 'mime_extension_mismatch';
        }

        return $failures;
    }

    /** @return list<string> */
    private function allowedFor(string $extension): array
    {
        $mimeTypes = $this->allowedMimeTypes[$extension] ?? null;
        if (! is_array($mimeTypes) || $mimeTypes === []) {
            throw new InvalidArgumentException('Filename extension is not allowed for document uploads.');
        }

        return $mimeTypes;
    }
}
