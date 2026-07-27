<?php

declare(strict_types=1);

namespace Modules\Audit\Domain;

use InvalidArgumentException;
use Modules\Audit\Contracts\AuditEventInput;
use RuntimeException;

final class AuditEventIdConflict extends RuntimeException
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $reason,
    ) {
        AuditEventInput::assertUuidV7($eventId, 'eventId');
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $reason) !== 1) {
            throw new InvalidArgumentException('audit_event_conflict_reason_invalid');
        }

        parent::__construct('audit_event_id_conflict');
    }
}
