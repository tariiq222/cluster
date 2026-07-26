<?php

namespace Modules\Organization\Features\UpdateCluster\Handler;

use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Organization\Infrastructure\Outbox\OrganizationOutbox;
use stdClass;

final class UpdateClusterHandler
{
    public function __construct(
        private readonly OrganizationOutbox $outbox,
    ) {}

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
            $this->outbox->insert($eventFactory($cluster), $row->id);

            return $cluster;
        });
    }
}
