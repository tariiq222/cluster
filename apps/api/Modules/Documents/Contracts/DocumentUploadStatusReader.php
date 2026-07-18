<?php

namespace Modules\Documents\Contracts;

use Modules\Documents\Application\AuthorizedDocumentActor;
use Modules\Documents\Application\DocumentUploadStatus;

interface DocumentUploadStatusReader
{
    public const OPERATION = 'documents.get-upload-status';

    public function get(AuthorizedDocumentActor $actor, string $uploadIntentId): DocumentUploadStatus;
}
