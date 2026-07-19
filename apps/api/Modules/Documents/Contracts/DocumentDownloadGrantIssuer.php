<?php

namespace Modules\Documents\Contracts;

use Modules\Documents\Application\DocumentDownloadGrant;

interface DocumentDownloadGrantIssuer
{
    public function issue(string $documentId, string $versionId, string $principalId): DocumentDownloadGrant;
}
