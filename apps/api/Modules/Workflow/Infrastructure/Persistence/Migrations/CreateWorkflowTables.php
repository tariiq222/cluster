<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 96)->unique();
            $table->string('source_record_type', 128);
            $table->uuid('created_by_user_id');
            $table->timestamps();
        });

        Schema::create('workflow_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workflow_definition_id');
            $table->unsignedInteger('version_number');
            $table->string('definition_state', 16);
            $table->json('graph_document');
            $table->char('graph_hash', 64);
            $table->string('dsl_version', 16)->default('1');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['workflow_definition_id', 'version_number']);
            $table->index(['workflow_definition_id', 'definition_state']);
        });

        Schema::create('workflow_instances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workflow_version_id');
            $table->string('source_module', 64);
            $table->string('source_type', 128);
            $table->string('source_id', 128);
            $table->string('state', 24)->default('running');
            $table->uuid('started_by_user_id');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->index(['source_module', 'source_type', 'source_id']);
            $table->index(['workflow_version_id', 'state']);
        });

        Schema::create('workflow_step_instances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workflow_instance_id');
            $table->string('node_key', 96);
            $table->string('node_type', 32);
            $table->string('state', 24);
            $table->unsignedInteger('activation_sequence')->default(1);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->uuid('task_id')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['workflow_instance_id', 'node_key', 'activation_sequence'], 'workflow_step_instances_instance_node_activation_unique');
            $table->index(['workflow_instance_id', 'state']);
        });

        Schema::create('workflow_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->uuid('principal_id');
            $table->string('operation', 96);
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->uuid('resource_id');
            $table->timestamps();
            $table->unique(['principal_id', 'operation', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_step_instances');
        Schema::dropIfExists('workflow_idempotency_keys');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflow_definitions');
    }
};
