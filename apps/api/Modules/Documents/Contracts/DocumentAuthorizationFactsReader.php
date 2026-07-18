<?php

namespace Modules\Documents\Contracts;

use Modules\Documents\Application\DocumentAuthorizationFacts;

/** Reads Documents-owned authorization facts without exposing storage implementation details. */
interface DocumentAuthorizationFactsReader
{
    public function forUploadIntent(string $uploadIntentId): DocumentAuthorizationFacts;

    public function forVersion(string $versionPublicId): DocumentAuthorizationFacts;
}
