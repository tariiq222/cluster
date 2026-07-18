<?php

namespace Modules\Documents\Features\Spreadsheet;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Documents\Application\AuthorizedDocumentActor;
use Modules\Documents\Application\CleanSpreadsheetDocument;
use Modules\Documents\Domain\DocumentScanStatus;
use Modules\Documents\Domain\DocumentStatus;
use Modules\Documents\Domain\DocumentVersionAvailabilityStatus;
use Modules\Documents\Domain\UuidV7;
use stdClass;

final class CleanSpreadsheetReferenceService
{
    public const OPERATION = 'documents.create-clean-spreadsheet-reference';

    public function fromVerifiedAvailableVersion(
        AuthorizedDocumentActor $actor,
        string $versionPublicId,
    ): CleanSpreadsheetDocument {
        UuidV7::assert($versionPublicId, 'Document version id');
        $row = DB::table('document_versions as versions')
            ->join('documents as documents', 'documents.id', '=', 'versions.document_id')
            ->where('versions.public_id', $versionPublicId)
            ->select([
                'documents.public_id as document_public_id',
                'documents.owner_organization_unit_id',
                'documents.status as document_status',
                'documents.legal_hold',
                'versions.public_id as version_public_id',
                'versions.original_filename',
                'versions.detected_mime_type',
                'versions.scan_status',
                'versions.availability_status',
            ])
            ->first();
        if (! $row instanceof stdClass) {
            throw new DomainException('document_version_not_found');
        }
        $actor->assertBoundTo(self::OPERATION, (string) $row->owner_organization_unit_id);
        if ($row->legal_hold || $row->document_status === DocumentStatus::Held->value) {
            throw new DomainException('document_held');
        }
        if ($row->scan_status !== DocumentScanStatus::Clean->value
            || $row->availability_status !== DocumentVersionAvailabilityStatus::Available->value) {
            throw new DomainException('clean_spreadsheet_provenance_unavailable');
        }
        if (! is_string($row->detected_mime_type)) {
            throw new DomainException('clean_spreadsheet_provenance_invalid');
        }

        return CleanSpreadsheetDocument::fromVerifiedAvailableProvenance(
            (string) $row->document_public_id,
            (string) $row->version_public_id,
            (string) $row->original_filename,
            $row->detected_mime_type,
        );
    }
}
