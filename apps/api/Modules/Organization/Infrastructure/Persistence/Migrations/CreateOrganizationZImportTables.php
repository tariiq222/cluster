<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('template_code', 64);
            $table->string('source_filename')->nullable();
            $table->string('source_format', 8);
            $table->string('status', 32)->default('received')->index();
            $table->uuid('quarantine_object_id');
            $table->uuid('submitted_by_user_id')->index();
            $table->uuid('approved_by_user_id')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->text('notes')->nullable();
            $table->text('decision_reason')->nullable();
            $table->dateTime('applied_at', 3)->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['template_code', 'status']);
        });

        Schema::create('import_rows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_job_id')->constrained('import_jobs')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->text('encrypted_payload');
            $table->string('proposed_action', 16)->nullable();
            $table->uuid('proposed_target_id')->nullable();
            $table->json('validation_errors')->nullable();
            $table->string('decision', 16)->nullable();
            $table->dateTime('applied_at', 3)->nullable();
            $table->timestamps();

            $table->unique(['import_job_id', 'row_number']);
            $table->index(['import_job_id', 'decision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_jobs');
    }
};
