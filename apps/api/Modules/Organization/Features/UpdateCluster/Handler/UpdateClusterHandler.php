<?php

namespace Modules\Organization\Features\UpdateCluster\Handler;

use Closure;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

final class UpdateClusterHandler
{
    /**
     * @param  array{name: string}  $changes
     * @param  Closure(array<string, mixed>): array<string, mixed>  $eventFactory
     * @return array<string, mixed>
     */
    public function update(int $expectedVersion, array $changes, Closure $eventFactory): array
    {
        return DB::transaction(function () use ($expectedVersion, $changes, $eventFactory): array {
            $row = DB::table('clusters')->lockForUpdate()->first();
            if (! $row instanceof stdClass) {
                throw new DomainException('cluster_not_found');
            }
            if ((int) $row->lock_version !== $expectedVersion) {
                throw new DomainException('precondition_failed');
            }

            $name = $changes['name'];
            if ($name === $row->name_ar) {
                throw new InvalidArgumentException('cluster_unchanged');
            }

            $version = (int) $row->lock_version + 1;
            $updated = DB::table('clusters')
                ->where('id', $row->id)
                ->where('lock_version', $expectedVersion)
                ->update([
                    'name_ar' => $name,
                    'lock_version' => $version,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new DomainException('precondition_failed');
            }
            $cluster = [
                'id' => $row->id,
                'code' => $row->code,
                'name_ar' => $name,
                'name_en' => $row->name_en,
                'status' => $row->status,
                'lock_version' => $version,
            ];
            $this->insertOutbox($eventFactory($cluster), $row->id);

            return $cluster;
        });
    }

    /** @param array<string, mixed> $cloudEvent */
    private function insertOutbox(array $cloudEvent, string $aggregateId): void
    {
        DB::table('outbox_events')->insert([
            'event_id' => $cloudEvent['id'],
            'aggregate_id' => $aggregateId,
            'event_type' => $cloudEvent['type'],
            'cloud_event' => json_encode($cloudEvent, JSON_THROW_ON_ERROR),
            'occurred_at' => (new DateTimeImmutable($cloudEvent['time']))->format('Y-m-d H:i:s'),
            'published_at' => null,
            'delivery_attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
