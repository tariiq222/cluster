<?php

namespace Modules\Documents\Application;

final readonly class InitiateDocumentUpload
{
    public function __construct(
        public string $purpose,
        public DocumentMetadata $metadata,
        public UploadFileMetadata $file,
        public ?string $documentId = null,
    ) {
        if (! in_array($this->purpose, ['document_version', 'organization_import_source'], true)) {
            throw new \InvalidArgumentException('Document upload purpose is unsupported.');
        }
    }
}
