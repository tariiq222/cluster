<?php

declare(strict_types=1);

namespace Modules\Audit\Domain;

/**
 * Section 8 — Frozen M01 export row projection.
 *
 * Every CSV / NDJSON row emitted by an export download streams exactly
 * these columns in this order. The contract is shared by the descriptor
 * serialization, the CSV header, the NDJSON keys, and the Audit tests
 * that assert byte-level determinism.
 *
 * No internal hash, integrity key version, request hash, or redacted
 * source value ever appears in any of these columns.
 */
final class AuditExportSection8Columns
{
    /** @var list<string> */
    public const COLUMNS = [
        'event_id',
        'occurred_at',
        'recorded_at',
        'source_module',
        'action',
        'event_type',
        'actor_type',
        'actor_id',
        'subject_type',
        'subject_id',
        'correlation_id',
        'outcome',
        'classification',
        'retention_until',
        'context',
    ];

    public const COLUMN_COUNT = 15;

    private function __construct() {}
}
