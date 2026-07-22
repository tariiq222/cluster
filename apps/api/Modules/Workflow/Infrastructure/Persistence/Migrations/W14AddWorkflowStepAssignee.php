<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An approval step owns its approver. Before this column the step had no way to
 * say who it belonged to, so the platform borrowed `tasks.assignee_user_id` and
 * every approval had to be materialised as a task row to reach a human.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_step_instances', function (Blueprint $table): void {
            $table->uuid('assignee_user_id')->nullable();
            $table->index(['assignee_user_id', 'state'], 'workflow_step_instances_assignee_state_index');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_step_instances', function (Blueprint $table): void {
            $table->dropIndex('workflow_step_instances_assignee_state_index');
            $table->dropColumn('assignee_user_id');
        });
    }
};
