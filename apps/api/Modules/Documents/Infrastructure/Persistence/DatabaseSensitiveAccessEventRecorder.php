<?php

namespace Modules\Documents\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Documents\Application\DocumentAccessRequest;
use Modules\Documents\Contracts\SensitiveAccessEventRecorder;
use Modules\Documents\Domain\UuidV7;

final class DatabaseSensitiveAccessEventRecorder implements SensitiveAccessEventRecorder
{
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
        $hash = hash('sha256', $request->idempotencyKey ?? implode('|', [$request->principalId, $documentId, $versionId, $request->correlationId]));
        if (DB::table('sensitive_access_events')
            ->where('idempotency_key_hash', $hash)
            ->where('resource_type', 'document')
            ->where('resource_id', $documentId)
            ->where('action', 'download')
            ->exists()) {
            return;
        }
        $id = UuidV7::generate();
        DB::table('sensitive_access_events')->insert([
            'id' => $id,
            'access_decision_id' => UuidV7::generate(),
            'actor_user_id' => $request->principalId,
            'original_actor_user_id' => $request->principalId,
            'resource_type' => 'document',
            'resource_id' => $documentId,
            'action' => 'download',
            'classification_code' => $classification,
            'correlation_id' => $request->correlationId,
            'source_ip' => $request->sourceIp,
            'device_fingerprint_hash' => $request->deviceFingerprintHash,
            'idempotency_key_hash' => $hash,
            'occurred_at' => now('UTC'),
            'recorded_at' => now('UTC'),
        ]);
    }
}
