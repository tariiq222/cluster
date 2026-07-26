<?php

namespace Modules\Documents\Infrastructure\Persistence;

use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\RecordSensitiveAccessEvent;
use Modules\Documents\Application\DocumentAccessRequest;
use Modules\Documents\Contracts\SensitiveAccessEventRecorder;

final class DatabaseSensitiveAccessEventRecorder implements SensitiveAccessEventRecorder
{
    public function __construct(
        private readonly RecordSensitiveAccessEvent $authorizationRecorder,
    ) {}

    public function recordDownload(
        string $documentId,
        string $versionId,
        string $classification,
        DocumentAccessRequest $request,
        AccessDecision $decision,
    ): void {
        if (! in_array($classification, ['confidential', 'top_secret'], true)) {
            return;
        }

        $this->authorizationRecorder->record([
            'idempotency_key' => $request->idempotencyKey ?? implode('|', [
                $request->principalId,
                $documentId,
                $versionId,
                $request->correlationId,
            ]),
            'principal_id' => $request->principalId,
            'source_ip' => $request->sourceIp,
            'device_fingerprint_hash' => $request->deviceFingerprintHash,
            'correlation_id' => $request->correlationId,
            'classification_code' => $classification,
            'resource_type' => 'document',
            'resource_id' => $documentId,
            'action' => 'download',
            'access_decision_id' => null,
        ]);
    }
}
