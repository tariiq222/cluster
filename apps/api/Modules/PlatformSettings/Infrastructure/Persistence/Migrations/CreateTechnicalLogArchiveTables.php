<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_log_archive_manifests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 24);
            $table->unsignedInteger('entry_count');
            $table->timestamp('first_occurred_at');
            $table->timestamp('last_occurred_at');
            $table->char('sha256', 64);
            $table->string('storage_reference', 512);
            $table->string('manifest_reference', 512);
            $table->timestamp('verified_at');
            $table->timestamps();
            $table->index(['status', 'verified_at']);
        });

        Schema::create('technical_log_archive_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 24);
            $table->uuid('manifest_id')->unique();
            $table->unsignedInteger('active_log_months');
            $table->json('source_entry_ids');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'archived_at']);
        });

        Schema::create('technical_log_archive_restore_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('manifest_id');
            $table->string('status', 24);
            $table->uuid('requested_by');
            $table->string('reason', 1000);
            $table->json('read_model')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
            $table->index(['manifest_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_log_archive_restore_requests');
        Schema::dropIfExists('technical_log_archive_batches');
        Schema::dropIfExists('technical_log_archive_manifests');
    }
};
