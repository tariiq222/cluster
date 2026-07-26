<?php

declare(strict_types=1);

namespace Shared\Contracts;

/**
 * Append-only recording of a sensitive access event. Authorization owns the
 * `sensitive_access_events` table; the same `Shared` interface is implemented
 * by Authorization's adapter and consumed by foreign modules (Identity, …) so
 * the dependency direction stays rank-correct.
 */
interface RecordSensitiveAccessEvent
{
    /**
     * @param  array{idempotency_key: string, principal_id: string, source_ip: ?string, device_fingerprint_hash: ?string, correlation_id: ?string, classification_code: string, resource_type: string, resource_id: string, action: string, access_decision_id: ?string}  $event
     */
    public function record(array $event): bool;
}
