<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 96)->unique('report_definitions_code_unique');
            $table->string('title', 240);
            $table->string('status', 16)->default('published');
            $table->string('projection_version', 32)->default('w1.9-v1');
            $table->timestamps(3);
        });

        Schema::create('dashboard_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 96)->unique('dashboard_definitions_code_unique');
            $table->string('title', 240);
            $table->uuid('report_id')->nullable();
            $table->string('status', 16)->default('published');
            $table->timestamps(3);

            $table->index(['report_id', 'status'], 'dashboard_report_status_index');
        });

        Schema::create('report_read_models', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('report_id');
            $table->string('source_module', 64);
            $table->string('source_type', 96);
            $table->string('source_id', 128);
            $table->string('source_version', 64);
            $table->uuid('scope_id')->nullable();
            $table->string('classification', 24)->default('internal');
            $table->string('projection_version', 32);
            $table->string('title', 240)->nullable();
            $table->json('safe_data')->nullable();
            $table->timestamps(3);

            $table->unique(['report_id', 'source_type', 'source_id'], 'report_source_unique');
            $table->index(['report_id', 'scope_id'], 'report_scope_index');
            $table->index(['source_type', 'source_version'], 'report_source_version_index');
        });

        Schema::create('report_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('report_id');
            $table->uuid('actor_id')->nullable();
            $table->uuid('scope_id')->nullable();
            $table->string('status', 16)->default('completed');
            $table->unsignedInteger('result_count')->default(0);
            $table->json('result')->nullable();
            $table->timestamps(3);

            $table->index(['report_id', 'scope_id'], 'run_report_scope_index');
        });

        Schema::create('export_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('report_run_id');
            $table->string('format', 8);
            $table->string('status', 16)->default('available');
            $table->unsignedInteger('result_count')->default(0);
            $table->json('safe_result')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps(3);

            $table->index(['report_run_id', 'status'], 'artifact_run_status_index');
        });

        Schema::create('report_inbox', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 128);
            $table->timestamps(3);
        });

        $now = now();
        DB::table('report_definitions')->insert([
            'id' => '019f7000-0000-7000-8000-000000000901',
            'code' => 'r1-work-records',
            'title' => 'طلبات نطاق المنشأة',
            'status' => 'published',
            'projection_version' => 'w1.9-v1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('dashboard_definitions')->insert([
            'id' => '019f7000-0000-7000-8000-000000000902',
            'code' => 'r1-work-records',
            'title' => 'لوحة طلبات المنشأة',
            'report_id' => '019f7000-0000-7000-8000-000000000901',
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('report_inbox');
        Schema::dropIfExists('export_artifacts');
        Schema::dropIfExists('report_runs');
        Schema::dropIfExists('report_read_models');
        Schema::dropIfExists('dashboard_definitions');
        Schema::dropIfExists('report_definitions');
    }
};
