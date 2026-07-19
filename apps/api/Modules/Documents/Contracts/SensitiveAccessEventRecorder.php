<?php

namespace Modules\Documents\Contracts;

use Modules\Authorization\Contracts\AccessDecision;
use Modules\Documents\Application\DocumentAccessRequest;

interface SensitiveAccessEventRecorder
{
    public function recordDownload(
        string $documentId,
        string $versionId,
        string $classification,
        DocumentAccessRequest $request,
        AccessDecision $decision,
    ): void;
}
