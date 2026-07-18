<?php

namespace Modules\Documents\Application;

final readonly class InitiateDocumentUpload
{
    public function __construct(
        public DocumentMetadata $metadata,
        public UploadFileMetadata $file,
    ) {}
}
