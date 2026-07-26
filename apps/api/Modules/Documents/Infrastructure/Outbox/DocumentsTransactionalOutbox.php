<?php

declare(strict_types=1);

namespace Modules\Documents\Infrastructure\Outbox;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Modules\Documents\Domain\Contracts\DocumentsOutbox;

/**
 * Default {@see DocumentsOutbox} implementation.
 *
 * Writes into the `document_outbox_events` table exclusively; the
 * table is owned by the Documents module per `ModuleBoundariesTest
 * ::TABLE_OWNERS`. The insert is inline (no `saveModel` commit hooks)
 * so it transparently participates in the caller's surrounding
 * transaction. Producers are responsible for opening that transaction
 * (or letting an outer one carry the event).
 */
final class DocumentsTransactionalOutbox implements DocumentsOutbox
{
    public function append(
        string $eventId,
        string $aggregateId,
        string $eventType,
        array $payload,
        ?DateTimeInterface $occurredAt = null,
    ): void {
        $utc = DateTimeImmutable::createFromInterface($occurredAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));

        $timestamp = $utc->format('Y-m-d H:i:s.u');

        DB::table('document_outbox_events')->insert([
            'id' => $eventId,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'occurred_at' => $timestamp,
            'published_at' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }
}
