<?php

declare(strict_types=1);

namespace Modules\Audit\Contracts;

/**
 * Frozen M00 contract: Records a single auditable event.
 *
 * Implementations must be atomic, idempotent, and redaction-applied. The
 * `eventId` is the deduplication key; a second call with the same eventId
 * returns the original receipt without writing a new row.
 */
interface RecordAuditEvent
{
    public function record(AuditEventInput $input): AuditEventReceipt;
}
