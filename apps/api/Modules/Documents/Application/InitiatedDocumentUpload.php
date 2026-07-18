<?php

namespace Modules\Documents\Application;

final readonly class InitiatedDocumentUpload
{
    public function __construct(
        public string $documentId,
        public string $versionId,
        public string $quarantineObjectId,
        public string $purpose,
        public int $maxSizeBytes,
        public SignedUploadIntent $uploadIntent,
    ) {}

    /** @return array{document_id: string, version_id: string, upload_intent: array{id: string, url: string, method: string, expires_at: string, required_headers: array<string, string>}} */
    public function toArray(): array
    {
        return [
            'document_id' => $this->documentId,
            'version_id' => $this->versionId,
            'upload_intent' => $this->uploadIntent->toArray(),
        ];
    }
}
