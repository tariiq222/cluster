<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addSystemAndReviewState();
        $this->createDecisionLedger();
        $this->addRuntimeResolutionState();
    }

    public function down(): void
    {
        if (Schema::hasTable('workflow_instances')) {
            Schema::table('workflow_instances', function (Blueprint $table): void {
                $columns = array_values(array_filter(
                    ['returned_at', 'return_reason'],
                    static fn (string $column): bool => Schema::hasColumn('workflow_instances', $column),
                ));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
        if (Schema::hasTable('workflow_step_instances')) {
            Schema::table('workflow_step_instances', function (Blueprint $table): void {
                $columns = array_values(array_filter(
                    ['resolution_attempted_at', 'assignment_rule'],
                    static fn (string $column): bool => Schema::hasColumn('workflow_step_instances', $column),
                ));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        Schema::dropIfExists('workflow_decisions');

        if (Schema::hasTable('workflow_versions')) {
            Schema::table('workflow_versions', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    'is_system',
                    'review_state',
                    'submitted_by_user_id',
                    'submitted_at',
                    'approved_by_user_id',
                    'approved_at',
                    'returned_by_user_id',
                    'return_reason',
                    'single_member_bootstrap_approval',
                ], static fn (string $column): bool => Schema::hasColumn('workflow_versions', $column)));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
        if (Schema::hasTable('workflow_definitions') && Schema::hasColumn('workflow_definitions', 'is_system')) {
            Schema::table('workflow_definitions', static function (Blueprint $table): void {
                $table->dropColumn('is_system');
            });
        }
    }

    private function addSystemAndReviewState(): void
    {
        if (Schema::hasTable('workflow_definitions') && ! Schema::hasColumn('workflow_definitions', 'is_system')) {
            Schema::table('workflow_definitions', static function (Blueprint $table): void {
                $table->boolean('is_system')->default(false)->index();
            });
        }
        if (! Schema::hasTable('workflow_versions')) {
            return;
        }

        Schema::table('workflow_versions', function (Blueprint $table): void {
            if (! Schema::hasColumn('workflow_versions', 'is_system')) {
                $table->boolean('is_system')->default(false)->index();
            }
            if (! Schema::hasColumn('workflow_versions', 'review_state')) {
                $table->string('review_state', 24)->default('draft')->index();
            }
            if (! Schema::hasColumn('workflow_versions', 'submitted_by_user_id')) {
                $table->uuid('submitted_by_user_id')->nullable()->index();
            }
            if (! Schema::hasColumn('workflow_versions', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable();
            }
            if (! Schema::hasColumn('workflow_versions', 'approved_by_user_id')) {
                $table->uuid('approved_by_user_id')->nullable()->index();
            }
            if (! Schema::hasColumn('workflow_versions', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
            if (! Schema::hasColumn('workflow_versions', 'returned_by_user_id')) {
                $table->uuid('returned_by_user_id')->nullable();
            }
            if (! Schema::hasColumn('workflow_versions', 'return_reason')) {
                $table->text('return_reason')->nullable();
            }
            if (! Schema::hasColumn('workflow_versions', 'single_member_bootstrap_approval')) {
                $table->boolean('single_member_bootstrap_approval')->default(false);
            }
        });

        DB::table('workflow_versions')
            ->where('definition_state', 'published')
            ->update(['review_state' => 'published']);
        DB::table('workflow_versions')
            ->where('definition_state', 'draft')
            ->whereNull('submitted_at')
            ->update(['review_state' => 'draft']);
    }

    private function createDecisionLedger(): void
    {
        if (! Schema::hasTable('workflow_decisions')) {
            Schema::create('workflow_decisions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workflow_step_id')->nullable();
                $table->uuid('workflow_instance_id')->nullable();
                $table->uuid('workflow_version_id')->nullable();
                $table->string('decision', 24);
                $table->text('reason')->nullable();
                $table->uuid('actor_user_id');
                $table->uuid('correlation_id')->nullable();
                $table->char('graph_hash', 64)->nullable();
                $table->boolean('single_member_bootstrap_approval')->default(false);
                $table->timestamp('decided_at');
                $table->timestamps();
                $table->unique('workflow_step_id', 'workflow_decisions_step_unique');
                $table->index('workflow_instance_id', 'workflow_decisions_instance_index');
                $table->index('workflow_version_id', 'workflow_decisions_version_index');
                $table->index(['actor_user_id', 'decided_at'], 'workflow_decisions_actor_time_index');
            });

            return;
        }

        Schema::table('workflow_decisions', function (Blueprint $table): void {
            if (! Schema::hasColumn('workflow_decisions', 'workflow_version_id')) {
                $table->uuid('workflow_version_id')->nullable()->index();
            }
            if (! Schema::hasColumn('workflow_decisions', 'graph_hash')) {
                $table->char('graph_hash', 64)->nullable();
            }
            if (! Schema::hasColumn('workflow_decisions', 'single_member_bootstrap_approval')) {
                $table->boolean('single_member_bootstrap_approval')->default(false);
            }
        });
    }

    private function addRuntimeResolutionState(): void
    {
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
};
