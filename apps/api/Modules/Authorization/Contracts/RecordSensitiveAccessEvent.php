<?php

namespace Modules\Authorization\Contracts;

/**
 * Append-only recording of a sensitive access event. Authorization owns the
 * `sensitive_access_events` table; foreign modules (Documents, …) ask
 * through this contract instead of writing directly.
 */
interface RecordSensitiveAccessEvent
{
    /**
     * @param  array{idempotency_key: string, principal_id: string, source_ip: ?string, device_fingerprint_hash: ?string, correlation_id: ?string, classification_code: string, resource_type: string, resource_id: string, action: string, access_decision_id: ?string}  $event
     */
    public function record(array $event): bool;
}
