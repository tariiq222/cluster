<?php

namespace Modules\Documents\Infrastructure\Persistence;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Documents\Application\DocumentAuthorizationFacts;
use Modules\Documents\Contracts\DocumentAuthorizationFactsReader;
use Modules\Documents\Domain\UuidV7;
use stdClass;

final class DatabaseDocumentAuthorizationFactsReader implements DocumentAuthorizationFactsReader
{
    public function forUploadIntent(string $uploadIntentId): DocumentAuthorizationFacts
    {
        UuidV7::assert($uploadIntentId, 'Upload intent id');
        $row = DB::table('document_upload_intents as intents')
            ->join('documents as documents', 'documents.id', '=', 'intents.document_id')
            ->where('intents.id', $uploadIntentId)
            ->select(['documents.owner_organization_unit_id', 'documents.classification'])
            ->first();

        return $this->facts($row, 'upload_intent_not_found');
    }

    public function forVersion(string $versionPublicId): DocumentAuthorizationFacts
    {
        UuidV7::assert($versionPublicId, 'Document version id');
        $row = DB::table('document_versions as versions')
            ->join('documents as documents', 'documents.id', '=', 'versions.document_id')
            ->where('versions.public_id', $versionPublicId)
            ->select(['documents.owner_organization_unit_id', 'documents.classification'])
            ->first();

        return $this->facts($row, 'document_version_not_found');
    }

    private function facts(mixed $row, string $notFound): DocumentAuthorizationFacts
    {
        if (! $row instanceof stdClass
            || ! is_string($row->owner_organization_unit_id)
            || ! is_string($row->classification)) {
            throw new DomainException($notFound);
        }

        return new DocumentAuthorizationFacts($row->owner_organization_unit_id, $row->classification);
    }
}
