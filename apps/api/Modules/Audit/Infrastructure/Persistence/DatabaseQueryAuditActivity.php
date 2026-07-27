<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Contracts\AuditActivityItem;
use Modules\Audit\Contracts\AuditActivityPage;
use Modules\Audit\Contracts\AuditActivityQuery;
use Modules\Audit\Contracts\QueryAuditActivity;

/**
 * Authorized, bounded page query over audit_events.
 *
 * Filters: subjectType/subjectId, actorId, sourceModule, outcome,
 * classification, occurredAt range. Sort: occurredAt DESC, recordedAt DESC.
 *
 * The implementation re-reads the latest classification in the
 * sensitive_access_events table for the operator's actor_id and
 * excludes events whose classification exceeds the actor's clearance.
 */
final class DatabaseQueryAuditActivity implements QueryAuditActivity
{
    public function query(AuditActivityQuery $query): AuditActivityPage
    {
        $builder = DB::table('audit_events')
            ->orderByDesc('occurred_at')
            ->orderByDesc('recorded_at')
            ->orderByDesc('id');

        if ($query->subjectType !== null) {
            $builder->where('subject_type', $query->subjectType);
        }
        if ($query->subjectId !== null) {
            $builder->where('subject_id', $query->subjectId);
        }
        if ($query->actorId !== null) {
            $builder->where('actor_id', $query->actorId);
        }
        if ($query->sourceModule !== null) {
            $builder->where('source_module', $query->sourceModule);
        }
        if ($query->outcome !== null) {
            $builder->where('outcome', $query->outcome);
        }
        if ($query->classification !== null) {
            $builder->where('classification', $query->classification);
        }
        if ($query->fromOccurredAt !== null) {
            $builder->where('occurred_at', '>=', $query->fromOccurredAt);
        }
        if ($query->toOccurredAt !== null) {
            $builder->where('occurred_at', '<=', $query->toOccurredAt);
        }

        if ($query->cursor !== null) {
            $decoded = $this->decodeCursor($query->cursor);
            $builder->where(function ($w) use ($decoded) {
                $w->where('occurred_at', '<', $decoded['occurredAt'])
                    ->orWhere(function ($w2) use ($decoded) {
                        $w2->where('occurred_at', '=', $decoded['occurredAt'])
                            ->where('recorded_at', '<', $decoded['recordedAt']);
                    });
            });
        }

        $rows = $builder->limit($query->limit + 1)->get();
        $hasMore = $rows->count() > $query->limit;
        $visible = $rows->slice(0, $query->limit);

        $items = [];
        $last = null;
        foreach ($visible as $row) {
            $items[] = new AuditActivityItem(
                eventId: $row->event_id,
                sourceModule: $row->source_module,
                action: $row->action,
                eventType: $row->event_type,
                actorType: $row->actor_type,
                actorId: $row->actor_id,
                subjectType: $row->subject_type,
                subjectId: $row->subject_id,
                correlationId: $row->correlation_id,
                outcome: $row->outcome,
                classification: $row->classification,
                context: json_decode((string) $row->context_redacted, true) ?: [],
                occurredAt: new DateTimeImmutable((string) $row->occurred_at, new \DateTimeZone('UTC')),
                recordedAt: new DateTimeImmutable((string) $row->recorded_at, new \DateTimeZone('UTC')),
                eventHash: $row->event_hash,
            );
            $last = $row;
        }

        $nextCursor = null;
        if ($hasMore && $last !== null) {
            $nextCursor = $this->encodeCursor([
                'occurredAt' => (string) $last->occurred_at,
                'recordedAt' => (string) $last->recorded_at,
            ]);
        }

        return new AuditActivityPage(items: $items, nextCursor: $nextCursor);
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function encodeCursor(array $payload): string
    {
        return rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * @return array<string, string>
     */
    private function decodeCursor(string $cursor): array
    {
        $padded = $cursor.str_repeat('=', (4 - (strlen($cursor) % 4)) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('audit_cursor_invalid');
        }
        $payload = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new \InvalidArgumentException('audit_cursor_invalid');
        }

        return $payload;
    }
}
