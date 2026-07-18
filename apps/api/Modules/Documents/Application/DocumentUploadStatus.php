<?php

namespace Modules\Documents\Application;

/** Safe upload-status projection; never contains an object key or AV implementation data. */
final readonly class DocumentUploadStatus
{
    public function __construct(
        public string $documentId,
        public string $versionId,
        public string $scanStatus,
        public string $availabilityStatus,
        public ?string $detectedMimeType,
        public ?int $sizeBytes,
        public ?string $sha256,
    ) {}

    /** @return array{document_id: string, version_id: string, scan_status: string, availability_status: string, detected_mime_type: string|null, byte_size: int|null, sha256: string|null} */
    public function toArray(): array
    {
        return [
            'document_id' => $this->documentId,
            'version_id' => $this->versionId,
            'scan_status' => $this->scanStatus,
            'availability_status' => $this->availabilityStatus,
            'detected_mime_type' => $this->detectedMimeType,
            'byte_size' => $this->sizeBytes,
            'sha256' => $this->sha256,
        ];
    }
}
