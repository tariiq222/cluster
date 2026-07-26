<?php

declare(strict_types=1);

namespace Modules\Documents\Domain\Contracts;

use DateTimeInterface;

/**
 * Module-owned outbox contract for Documents producers.
 *
 * Producers inject this interface; the implementation is the only
 * writer to the `document_outbox_events` table. The architecture test
 * `tests/Architecture/ModuleBoundariesTest` enforces this boundary by
 * flagging any `DB::table('document_outbox_events')` access outside
 * the bound implementation as a cross-owner SQL reference.
 *
 * Writes MUST participate in the caller's surrounding transaction so
 * the outbox row commits atomically with the documents/state change
 * that produced it. The implementation is responsible for skipping
 * commit hooks that would otherwise flush a non-existent implicit
 * transaction.
 */
interface DocumentsOutbox
{
    /**
     * Append a Documents-owned outbox event.
     *
     * @param  string  $eventId  UUIDv7 identifier; used for replay/idempotency by relay workers.
     * @param  string  $aggregateId  The aggregate (typically `document_id`) the event describes.
     * @param  string  $eventType  The CloudEvent type, e.g. `com.cluster.documents.metadataupdated.v1`.
     * @param  array<string, mixed>  $payload  The CloudEvent payload; serialized verbatim into `payload`.
     * @param  DateTimeInterface|null  $occurredAt  Optional event timestamp; defaults to the adapter's current UTC time. Accepts
     *                                              Carbon / DateTime / DateTimeImmutable; the adapter normalizes to UTC.
     */
    public function append(
        string $eventId,
        string $aggregateId,
        string $eventType,
        array $payload,
        ?DateTimeInterface $occurredAt = null,
    ): void;
}
