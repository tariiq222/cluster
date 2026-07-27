<?php

declare(strict_types=1);

namespace Shared\Contracts;

/**
 * Cross-module port for recording a single auditable event. Modules
 * (Authorization, Documents, etc.) depend on this interface in `Shared`
 * so the dependency direction stays rank-correct: foreign modules do not
 * import `Modules\Audit\Contracts\*` directly. The `Audit` module owns
 * the implementation and the `audit_events` table; other modules see
 * only the same `AuditEventInput` shape the audit domain uses
 * internally, exposed as a plain-data array here so foreign callers do
 * not need to import any `Modules\Audit` symbol.
 */
interface RecordAuditEvent
{
    /**
     * @param  array{event_id: string, source_module: string, action: string, event_type: string, actor_type: string, actor_id: ?string, original_actor_id: ?string, subject_type: string, subject_id: ?string, correlation_id: string, outcome: string, classification: string, context: array<string, mixed>, occurred_at: string, retention_class: string}  $event
     */
    public function record(array $event): void;
}
