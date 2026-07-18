<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->uuid('created_by_user_id');
            $table->uuid('assignee_user_id');
            $table->uuid('owner_organization_unit_id')->nullable();
            $table->string('status', 32)->index();
            $table->string('priority', 16)->default('normal');
            $table->string('classification', 24)->default('internal');
            $table->string('completion_policy', 32)->default('direct');
            $table->string('source_module', 64)->nullable();
            $table->string('source_type', 128)->nullable();
            $table->string('source_id', 128)->nullable();
            $table->uuid('workflow_step_id')->nullable()->unique();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['assignee_user_id', 'status']);
        });

        Schema::create('task_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->uuid('principal_id');
            $table->string('operation', 96);
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->uuid('task_id');
            $table->timestamps();
            $table->unique(['principal_id', 'operation', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_idempotency_keys');
        Schema::dropIfExists('tasks');
    }
};
