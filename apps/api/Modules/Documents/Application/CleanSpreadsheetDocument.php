<?php

namespace Modules\Documents\Application;

use DomainException;
use Modules\Documents\Domain\UuidV7;

final readonly class CleanSpreadsheetDocument
{
    public string $format;

    private function __construct(
        public string $documentId,
        public string $versionId,
        public string $sourceFilename,
        public string $detectedMimeType,
    ) {
        UuidV7::assert($this->documentId, 'Spreadsheet document id');
        UuidV7::assert($this->versionId, 'Spreadsheet version id');

        $extension = strtolower(pathinfo($this->sourceFilename, PATHINFO_EXTENSION));
        $format = match ($extension) {
            'csv' => 'csv',
            'xlsx' => 'xlsx',
            default => throw new DomainException('clean_spreadsheet_format_unsupported'),
        };
        $allowedMimeTypes = $format === 'csv'
            ? ['text/csv', 'text/plain']
            : ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        if (! in_array($this->detectedMimeType, $allowedMimeTypes, true)) {
            throw new DomainException('clean_spreadsheet_mime_invalid');
        }

        $this->format = $format;
    }

    /** Only Documents provenance services may issue this reference after verifying clean availability. */
    public static function fromVerifiedAvailableProvenance(
        string $documentId,
        string $versionId,
        string $sourceFilename,
        string $detectedMimeType,
    ): self {
        return new self($documentId, $versionId, $sourceFilename, $detectedMimeType);
    }
}
