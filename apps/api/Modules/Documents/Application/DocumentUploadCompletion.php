<?php

namespace Modules\Documents\Application;

final readonly class DocumentUploadCompletion
{
    /** @param list<string> $failureCodes */
    public function __construct(
        public bool $accepted,
        public string $documentId,
        public string $versionId,
        public string $scanStatus,
        public string $availabilityStatus,
        public array $failureCodes,
    ) {}

    /** @return array{accepted: bool, document_id: string, version_id: string, scan_status: string, availability_status: string, failure_codes: list<string>} */
    public function toArray(): array
    {
        return [
            'accepted' => $this->accepted,
            'document_id' => $this->documentId,
            'version_id' => $this->versionId,
            'scan_status' => $this->scanStatus,
            'availability_status' => $this->availabilityStatus,
            'failure_codes' => $this->failureCodes,
        ];
    }
}
