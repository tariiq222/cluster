<?php

namespace Modules\Documents\Application;

final readonly class DocumentScanResult
{
    public function __construct(
        public string $documentId,
        public string $versionId,
        public string $scanStatus,
        public string $availabilityStatus,
    ) {}

    /** @return array{document_id: string, version_id: string, scan_status: string, availability_status: string} */
    public function toArray(): array
    {
        return [
            'document_id' => $this->documentId,
            'version_id' => $this->versionId,
            'scan_status' => $this->scanStatus,
            'availability_status' => $this->availabilityStatus,
        ];
    }
}
