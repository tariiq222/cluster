<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'workflow_instances_source_version_unique_w23';

    public function up(): void
    {
        if (! Schema::hasTable('workflow_instances')
            || Schema::hasIndex(
                'workflow_instances',
                ['source_module', 'source_type', 'source_id', 'workflow_version_id'],
                'unique',
            )) {
            return;
        }

        $duplicatesExist = DB::table('workflow_instances')
            ->select('source_module', 'source_type', 'source_id', 'workflow_version_id')
            ->groupBy('source_module', 'source_type', 'source_id', 'workflow_version_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicatesExist) {
            throw new LogicException('workflow_instances_source_version_unique_requires_duplicate_remediation');
        }

        Schema::table('workflow_instances', static function (Blueprint $table): void {
            $table->unique(
                ['source_module', 'source_type', 'source_id', 'workflow_version_id'],
                self::INDEX,
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('workflow_instances')) {
            return;
        }

        $createdByThisMigration = array_filter(
            Schema::getIndexes('workflow_instances'),
            static fn (array $index): bool => $index['name'] === self::INDEX,
        );
        if ($createdByThisMigration !== []) {
            Schema::table('workflow_instances', static function (Blueprint $table): void {
                $table->dropUnique(self::INDEX);
            });
        }
    }
};
