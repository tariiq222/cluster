<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workflow_versions')) {
            return;
        }

        Schema::table('workflow_versions', function (Blueprint $table): void {
            if (! Schema::hasColumn('workflow_versions', 'submitted_by_user_id')) {
                $table->uuid('submitted_by_user_id')->nullable();
            }
            if (! Schema::hasColumn('workflow_versions', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable();
            }
            if (! Schema::hasColumn('workflow_versions', 'approved_by_user_id')) {
                $table->uuid('approved_by_user_id')->nullable();
            }
            if (! Schema::hasColumn('workflow_versions', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
            if (! Schema::hasColumn('workflow_versions', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }
            if (! Schema::hasColumn('workflow_versions', 'approval_status')) {
                $table->string('approval_status', 16)->default('draft');
            }
            if (! Schema::hasColumn('workflow_versions', 'review_state')) {
                $table->string('review_state', 16)->default('draft');
            }
            if (! Schema::hasColumn('workflow_versions', 'usage_description')) {
                $table->text('usage_description')->nullable();
            }
            if (! Schema::hasColumn('workflow_versions', 'scope')) {
                $table->json('scope')->nullable();
            }
            if (! Schema::hasColumn('workflow_versions', 'single_member_bootstrap_approval')) {
                $table->boolean('single_member_bootstrap_approval')->default(false);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('workflow_versions')) {
            return;
        }

        Schema::table('workflow_versions', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                'submitted_by_user_id',
                'submitted_at',
                'approved_by_user_id',
                'approved_at',
                'rejection_reason',
                'review_state',
                'usage_description',
                'scope',
                'single_member_bootstrap_approval',
            ], static fn (string $column): bool => Schema::hasColumn('workflow_versions', $column)));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
