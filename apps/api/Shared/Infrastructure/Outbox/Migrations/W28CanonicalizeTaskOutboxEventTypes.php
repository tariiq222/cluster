<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const TYPES = [
        'task.created.v1' => 'com.cluster.tasks.created.v1',
        'task.assigned.v1' => 'com.cluster.tasks.assigned.v1',
        'task.start.v1' => 'com.cluster.tasks.started.v1',
        'task.block.v1' => 'com.cluster.tasks.blocked.v1',
        'task.unblock.v1' => 'com.cluster.tasks.unblocked.v1',
        'task.complete.v1' => 'com.cluster.tasks.completed.v1',
        'task.cancel.v1' => 'com.cluster.tasks.cancelled.v1',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('outbox_events')) {
            return;
        }

        DB::transaction(function (): void {
            $rows = DB::table('outbox_events')
                ->whereNull('published_at')
                ->whereIn('event_type', array_keys(self::TYPES))
                ->orderBy('occurred_at')
                ->lockForUpdate()
                ->get(['event_id', 'event_type', 'cloud_event']);

            foreach ($rows as $row) {
                $canonical = self::TYPES[(string) $row->event_type] ?? null;
                $envelope = json_decode((string) $row->cloud_event, true);
                if ($canonical === null || ! is_array($envelope)) {
                    throw new \RuntimeException('tasks_outbox_event_canonicalization_invalid_envelope');
                }

                $envelope['type'] = $canonical;
                $envelope['source'] = '/'.$canonical;
                DB::table('outbox_events')->where('event_id', $row->event_id)->update([
                    'event_type' => $canonical,
                    'cloud_event' => json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /** Forward-only: published event history and canonical names are immutable. */
    public function down(): void {}
};
