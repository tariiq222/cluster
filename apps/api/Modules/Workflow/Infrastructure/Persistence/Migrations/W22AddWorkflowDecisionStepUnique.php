<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'workflow_decisions_step_unique_w22';

    public function up(): void
    {
        if (! Schema::hasTable('workflow_decisions')
            || Schema::hasIndex('workflow_decisions', ['workflow_step_id'], 'unique')) {
            return;
        }

        $duplicatesExist = DB::table('workflow_decisions')
            ->select('workflow_step_id')
            ->whereNotNull('workflow_step_id')
            ->groupBy('workflow_step_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicatesExist) {
            throw new \LogicException('workflow_decisions_step_unique_requires_duplicate_remediation');
        }

        Schema::table('workflow_decisions', static function (Blueprint $table): void {
            $table->unique('workflow_step_id', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('workflow_decisions')) {
            return;
        }
        $createdByThisMigration = array_filter(
            Schema::getIndexes('workflow_decisions'),
            static fn (array $index): bool => $index['name'] === self::INDEX,
        );
        if ($createdByThisMigration !== []) {
            Schema::table('workflow_decisions', static function (Blueprint $table): void {
                $table->dropUnique(self::INDEX);
            });
        }
    }
};
