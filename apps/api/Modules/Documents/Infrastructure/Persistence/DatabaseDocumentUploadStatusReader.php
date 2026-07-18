<?php

namespace Modules\Documents\Infrastructure\Persistence;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Documents\Application\AuthorizedDocumentActor;
use Modules\Documents\Application\DocumentUploadStatus;
use Modules\Documents\Contracts\DocumentUploadStatusReader;
use Modules\Documents\Domain\UuidV7;
use stdClass;

final class DatabaseDocumentUploadStatusReader implements DocumentUploadStatusReader
{
    public function get(AuthorizedDocumentActor $actor, string $uploadIntentId): DocumentUploadStatus
    {
        UuidV7::assert($uploadIntentId, 'Upload intent id');
        $row = DB::table('document_upload_intents as intents')
            ->join('documents as documents', 'documents.id', '=', 'intents.document_id')
            ->join('document_versions as versions', 'versions.id', '=', 'intents.document_version_id')
            ->where('intents.id', $uploadIntentId)
            ->select([
                'documents.public_id as document_public_id',
                'documents.owner_organization_unit_id',
                'versions.public_id as version_public_id',
                'versions.scan_status',
                'versions.availability_status',
                'versions.detected_mime_type',
                'versions.size_bytes',
                'versions.sha256',
                'intents.completed_at',
            ])
            ->first();
        if (! $row instanceof stdClass) {
            throw new DomainException('upload_intent_not_found');
        }
        if (! is_string($row->owner_organization_unit_id)
            || ! is_string($row->document_public_id)
            || ! is_string($row->version_public_id)
            || ! is_string($row->scan_status)
            || ! is_string($row->availability_status)) {
            throw new DomainException('document_status_unavailable');
        }
        $actor->assertBoundTo(self::OPERATION, $row->owner_organization_unit_id);
        $completed = $row->completed_at !== null;

        return new DocumentUploadStatus(
            $row->document_public_id,
            $row->version_public_id,
            $row->scan_status,
            $row->availability_status,
            $completed && is_string($row->detected_mime_type) ? $row->detected_mime_type : null,
            $completed && is_numeric($row->size_bytes) ? (int) $row->size_bytes : null,
            $completed && is_string($row->sha256) ? $row->sha256 : null,
        );
    }
}
