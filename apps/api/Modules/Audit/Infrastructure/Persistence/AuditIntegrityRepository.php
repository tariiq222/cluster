<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;

/**
 * Walks the audit_events table in stream_sequence order and records a
 * checkpoint of the current chain head. The application never updates
 * audit_events; integrity verification compares each row's
 * previous_event_hash with the preceding row's event_hash.
 */
final class AuditIntegrityRepository
{
    public function verify(string $correlationId, string $verificationId): object
    {
        $expectedPrevious = str_repeat('0', 64);
        $verifiedCount = 0;
        $lastEventId = 0;
        $lastHash = $expectedPrevious;
        $status = 'succeeded';

        $rows = DB::table('audit_events')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $actualPrevious = (string) ($row->previous_event_hash ?? str_repeat('0', 64));
            if ($actualPrevious !== $expectedPrevious) {
                $status = 'failed';
                break;
            }
            $lastHash = (string) $row->event_hash;
            $expectedPrevious = $lastHash;
            $lastEventId = (int) $row->id;
            $verifiedCount++;
        }

        $now = now('UTC');
        DB::table('audit_integrity_checkpoints')->insert([
            'verification_id' => $verificationId,
            'correlation_id' => $correlationId,
            'anchor_hash' => $lastHash,
            'last_event_id' => $lastEventId,
            'verified_event_count' => $verifiedCount,
            'status' => $status,
            'verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (object) [
            'verification_id' => $verificationId,
            'anchor_hash' => $lastHash,
            'last_event_id' => $lastEventId,
            'verified_event_count' => $verifiedCount,
            'status' => $status,
        ];
    }

    public function latestAnchor(): ?string
    {
        $row = DB::table('audit_integrity_checkpoints')
            ->orderByDesc('id')
            ->first();

        return $row?->anchor_hash;
    }
}
