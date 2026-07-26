<?php

namespace Modules\Authorization\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\RecordSensitiveAccessEvent;
use Modules\Authorization\Domain\UuidV7;
use Shared\Contracts\RecordSensitiveAccessEvent as SharedRecordSensitiveAccessEvent;

final class DatabaseRecordSensitiveAccessEvent implements RecordSensitiveAccessEvent, SharedRecordSensitiveAccessEvent
{
    /**
     * @param  array{idempotency_key: string, principal_id: string, source_ip: ?string, device_fingerprint_hash: ?string, correlation_id: ?string, classification_code: string, resource_type: string, resource_id: string, action: string, access_decision_id: ?string}  $event
     */
    public function record(array $event): bool
    {
        $hash = hash('sha256', $event['idempotency_key']);

        if (DB::table('sensitive_access_events')
            ->where('idempotency_key_hash', $hash)
            ->where('resource_type', $event['resource_type'])
            ->where('resource_id', $event['resource_id'])
            ->where('action', $event['action'])
            ->exists()) {
            return false;
        }

        $now = now('UTC');
        DB::table('sensitive_access_events')->insert([
            'id' => UuidV7::generate(),
            'access_decision_id' => $event['access_decision_id'],
            'actor_user_id' => $event['principal_id'],
            'original_actor_user_id' => $event['principal_id'],
            'resource_type' => $event['resource_type'],
            'resource_id' => $event['resource_id'],
            'action' => $event['action'],
            'classification_code' => $event['classification_code'],
            'correlation_id' => $event['correlation_id'],
            'source_ip' => $event['source_ip'],
            'device_fingerprint_hash' => $event['device_fingerprint_hash'],
            'idempotency_key_hash' => $hash,
            'occurred_at' => $now,
            'recorded_at' => $now,
        ]);

        return true;
    }
}
