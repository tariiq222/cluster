<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks')) {
            return;
        }

        $columns = array_values(array_filter(
            ['workflow_step_id', 'source_module', 'source_type', 'source_id'],
            static fn (string $column): bool => Schema::hasColumn('tasks', $column),
        ));
        if ($columns === []) {
            return;
        }

        $workflowStepUnique = $this->workflowStepUniqueIndex();
        if ($workflowStepUnique !== null) {
            Schema::table('tasks', static fn (Blueprint $table) => $table->dropUnique($workflowStepUnique));
        }
        Schema::table('tasks', static fn (Blueprint $table) => $table->dropColumn($columns));
    }

    public function down(): void
    {
        // The removed links belonged to retired modules and cannot be restored safely.
    }

    private function workflowStepUniqueIndex(): ?string
    {
        foreach (Schema::getIndexes('tasks') as $index) {
            $columns = array_map('strtolower', $index['columns'] ?? []);
            if (($index['unique'] ?? false) === true && $columns === ['workflow_step_id']) {
                return is_string($index['name'] ?? null) ? $index['name'] : null;
            }
        }

        return null;
    }
};
