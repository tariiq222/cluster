<?php

declare(strict_types=1);

namespace Modules\Organization\Infrastructure\Outbox;

use Shared\Contracts\TransactionalOutboxEnvelope;

/**
 * Module-owned outbox façade for Organization producers.
 *
 * Owns the producer-side CloudEvent assembly (event-id derivation,
 * cloud_event payload, time-stamping) and forwards the verbatim
 * envelope to the Shared {@see TransactionalOutboxEnvelope}
 * implementation. Direct `DB::table('outbox_events')` access is
 * intentionally absent: the architecture scanner
 * (`Tests\Architecture\ModuleBoundariesTest`) flags any producer
 * module that bypasses the Shared contract.
 *
 * Event-catalog validation is owned by the Shared adapter boundary; this
 * façade only assembles and forwards the producer's envelope.
 */
final class OrganizationOutbox
{
    public function __construct(
        private readonly TransactionalOutboxEnvelope $outbox,
    ) {}

    /** @param array<string, mixed> $cloudEvent */
    public function insert(array $cloudEvent, string $aggregateId): void
    {

        $occurredAt = isset($cloudEvent['time']) && is_string($cloudEvent['time'])
            ? $cloudEvent['time']
            : now()->utc()->format('Y-m-d\TH:i:s.v\Z');

        $this->outbox->appendEnvelope(
            (string) $cloudEvent['id'],
            $aggregateId,
            $cloudEvent,
            $occurredAt,
        );
    }
}
