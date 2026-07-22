<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2 of docs/plans/approvals-and-requests.md introduces an explicit
 * decision log so an approval step can record approve/reject/return/accept/decline
 * with the actor who decided, the step that was decided, and the instance that
 * owned it. Before this table the only record of a decision was the step state
 * mutation, which lost who said yes and why.
 *
 * The same stage also records the assignment rule used to route a step and the
 * moment the platform tried to resolve it, and lets an instance carry a return
 * reason when a step sends the workflow back to an earlier node.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workflow_decisions')) {
            Schema::create('workflow_decisions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workflow_step_id');
                $table->uuid('workflow_instance_id');
                $table->string('decision', 16);
                $table->text('reason')->nullable();
                $table->uuid('actor_user_id');
                $table->uuid('correlation_id')->nullable();
                $table->timestamp('decided_at');
                $table->timestamps();
                $table->index('workflow_instance_id', 'idx_workflow_decisions_instance');
                $table->index('actor_user_id', 'idx_workflow_decisions_actor');
                $table->index('workflow_step_id', 'idx_workflow_decisions_step');
            });
        }

        if (Schema::hasTable('workflow_step_instances')) {
            Schema::table('workflow_step_instances', function (Blueprint $table): void {
                if (! Schema::hasColumn('workflow_step_instances', 'assignment_rule')) {
                    $table->json('assignment_rule')->nullable();
                }
                if (! Schema::hasColumn('workflow_step_instances', 'resolution_attempted_at')) {
                    $table->timestamp('resolution_attempted_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('workflow_instances')) {
            Schema::table('workflow_instances', function (Blueprint $table): void {
                if (! Schema::hasColumn('workflow_instances', 'return_reason')) {
                    $table->text('return_reason')->nullable();
                }
                if (! Schema::hasColumn('workflow_instances', 'returned_at')) {
                    $table->timestamp('returned_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('workflow_instances')) {
            Schema::table('workflow_instances', function (Blueprint $table): void {
                if (Schema::hasColumn('workflow_instances', 'returned_at')) {
                    $table->dropColumn('returned_at');
                }
                if (Schema::hasColumn('workflow_instances', 'return_reason')) {
                    $table->dropColumn('return_reason');
                }
            });
        }

        if (Schema::hasTable('workflow_step_instances')) {
            Schema::table('workflow_step_instances', function (Blueprint $table): void {
                if (Schema::hasColumn('workflow_step_instances', 'resolution_attempted_at')) {
                    $table->dropColumn('resolution_attempted_at');
                }
                if (Schema::hasColumn('workflow_step_instances', 'assignment_rule')) {
                    $table->dropColumn('assignment_rule');
                }
            });
        }

        Schema::dropIfExists('workflow_decisions');
    }
};
