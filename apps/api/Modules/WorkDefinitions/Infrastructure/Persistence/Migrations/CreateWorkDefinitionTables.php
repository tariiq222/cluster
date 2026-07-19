<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 96)->unique();
            $table->string('name', 255);
            $table->string('description', 2000)->nullable();
            $table->string('default_classification', 24)->default('internal');
            $table->uuid('created_by_user_id');
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });
        Schema::create('work_definition_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('work_definition_id');
            $table->unsignedInteger('version_number');
            $table->string('status', 16)->default('draft');
            $table->json('schema_document');
            $table->string('field_policy_key', 128);
            $table->char('schema_hash', 64);
            $table->string('change_summary', 2000)->nullable();
            $table->uuid('created_by_user_id');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['work_definition_id', 'version_number']);
            $table->index(['work_definition_id', 'status']);
        });
        Schema::create('work_definition_idempotency_keys', function (Blueprint $table): void {
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
        Schema::dropIfExists('work_definition_idempotency_keys');
        Schema::dropIfExists('work_definition_versions');
        Schema::dropIfExists('work_definitions');
    }
};
