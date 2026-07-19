<?php

namespace Modules\Documents\Application;

use DateTimeImmutable;

final readonly class DocumentDownloadGrant
{
    public function __construct(
        public string $documentId,
        public string $versionId,
        public string $url,
        public DateTimeImmutable $expiresAt,
        public string $correlationId,
    ) {}

    /** @return array{document_id:string,version_id:string,url:string,expires_at:string,correlation_id:string} */
    public function toArray(): array
    {
        return [
            'document_id' => $this->documentId,
            'version_id' => $this->versionId,
            'url' => $this->url,
            'expires_at' => $this->expiresAt->format('Y-m-d\\TH:i:s.v\\Z'),
            'correlation_id' => $this->correlationId,
        ];
    }
}
