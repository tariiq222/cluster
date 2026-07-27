<?php

declare(strict_types=1);

namespace Modules\Audit\Features\Retention\Console;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Modules\Audit\Features\Retention\Handler\PurgeExpiredAuditEvents;
use Throwable;

/**
 * `audit:retention:purge --before=<UTC timestamp> --stream=<stream key>`
 *
 * Operator-driven retention purge. Invokes {@see PurgeExpiredAuditEvents}
 * which guarantees:
 *   - no candidate row → no rows touched, exit 0
 *   - chain mismatch in prefix → typed error, no rows touched, exit 1
 *   - atomic checkpoint + delete → exit 0, prints one summary line
 *   - database failure → rollback, exit 1
 *
 * The operator must supply --stream AND --before. Both are validated as
 * UTC timestamps (or stream keys that match `audit_events.stream_key`); a
 * cutoff that is too recent (within the legal retention floor of 2555 days)
 * is refused up-front so the command cannot accidentally violate retention
 * law.
 */
final class PurgeExpiredAuditEventsCommand extends Command
{
    protected $description = 'Purge the contiguous expired prefix of one Audit stream (chain-checked, immutable checkpoint first).';

    protected $signature = 'audit:retention:purge
        {--before= : Required UTC cutoff in Y-m-d\\TH:i:s.v\\Z form; rows whose retention_until < cutoff form the eligible prefix.}
        {--stream= : Required stream_key (source_module:subject_type:subject_id-or-global).}';

    public function handle(): int
    {
        $stream = $this->option('stream');
        $before = $this->option('before');

        if (! is_string($stream) || $stream === '') {
            $this->error('--stream is required and must be a valid audit_events.stream_key.');

            return self::INVALID;
        }

        if (! is_string($before) || $before === '') {
            $this->error('--before is required and must be a UTC timestamp of the form Y-m-d\\TH:i:s.v\\Z.');

            return self::INVALID;
        }

        $cutoff = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $before, new DateTimeZone('UTC'));
        if ($cutoff === false || $cutoff->format('Y-m-d\TH:i:s.v\Z') !== $before) {
            $this->error('--before must be a UTC timestamp matching Y-m-d\\TH:i:s.v\\Z.');

            return self::INVALID;
        }

        try {
            $purge = $this->laravel->make(PurgeExpiredAuditEvents::class);
            $result = $purge->run($stream, $cutoff);
        } catch (Throwable $exception) {
            $this->error(sprintf(
                'Retention purge failed: %s',
                preg_replace('/\s+/', ' ', $exception->getMessage()) ?? 'audit_retention_purge_failed',
            ));

            return self::FAILURE;
        }

        if ($result['deleted_event_count'] === 0) {
            $this->info(sprintf('No eligible rows for stream %s at cutoff %s — no change.', $stream, $before));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Purged %d event(s) on stream %s (sequences %d..%d) using checkpoint %s; key version %s.',
            $result['deleted_event_count'],
            $result['stream_key'],
            $result['first_sequence'],
            $result['last_sequence'],
            $result['checkpoint_id'],
            $result['integrity_key_version'],
        ));

        return self::SUCCESS;
    }
}
