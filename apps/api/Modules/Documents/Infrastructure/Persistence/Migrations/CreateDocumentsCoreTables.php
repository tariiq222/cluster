<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('public_id')->unique();
            $table->uuid('owner_organization_unit_id')->index();
            $table->uuid('created_by_user_id')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('classification', 24)->index();
            $table->string('status', 24)->index();
            $table->uuid('current_version_id')->nullable();
            $table->dateTime('retention_until', 3)->nullable();
            $table->string('retention_policy_key', 128)->nullable();
            $table->boolean('legal_hold')->default(false)->index();
            $table->string('legal_hold_reason', 1000)->nullable();
            $table->dateTime('legal_hold_at', 3)->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['owner_organization_unit_id', 'status']);
            $table->index(['classification', 'status']);
        });

        Schema::create('document_storage_objects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('disk', 64);
            $table->string('object_key', 512)->unique();
            $table->string('storage_class', 24)->index();
            $table->boolean('immutable')->default(false);
            $table->dateTime('immutable_since', 3)->nullable();
            $table->timestamps();
        });

        Schema::create('document_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('public_id')->unique();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('storage_object_id')->constrained('document_storage_objects')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('original_filename');
            $table->string('declared_mime_type', 128);
            $table->string('detected_mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64)->nullable();
            $table->string('scan_status', 24)->index();
            $table->string('availability_status', 24)->index();
            $table->string('scan_engine_version', 128)->nullable();
            $table->json('scan_result')->nullable();
            $table->dateTime('scanned_at', 3)->nullable();
            $table->dateTime('available_at', 3)->nullable();
            $table->uuid('created_by_user_id')->index();
            $table->timestamps();

            $table->unique(['document_id', 'version_number']);
            $table->index(['document_id', 'availability_status']);
            $table->index(['scan_status', 'created_at']);
            $table->index('sha256');
        });

        Schema::create('document_upload_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('document_version_id')->constrained('document_versions')->cascadeOnDelete();
            $table->foreignUuid('storage_object_id')->constrained('document_storage_objects')->restrictOnDelete();
            $table->dateTime('expires_at', 3)->index();
            $table->dateTime('completed_at', 3)->nullable();
            $table->timestamps();

            $table->unique('document_version_id');
        });

        Schema::create('document_quarantines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_version_id')->constrained('document_versions')->cascadeOnDelete();
            $table->foreignUuid('storage_object_id')->constrained('document_storage_objects')->restrictOnDelete();
            $table->foreignUuid('upload_intent_id')->constrained('document_upload_intents')->cascadeOnDelete();
            $table->boolean('sha256_verified')->default(false);
            $table->boolean('size_verified')->default(false);
            $table->boolean('mime_verified')->default(false);
            $table->string('detected_mime_type', 128)->nullable();
            $table->string('scan_engine', 128)->nullable();
            $table->string('scan_signature_version', 128)->nullable();
            $table->string('scanner_outcome', 24)->nullable();
            $table->string('policy_verdict', 24)->index();
            $table->json('failure_codes')->nullable();
            $table->dateTime('scanned_at', 3)->nullable();
            $table->timestamps();

            $table->unique('document_version_id');
            $table->unique('upload_intent_id');
        });

        Schema::create('document_idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('principal_id');
            $table->string('operation', 96);
            $table->char('idempotency_key_hash', 64);
            $table->char('request_hash', 64);
            $table->string('resource_type', 32);
            $table->uuid('resource_id');
            $table->json('response_payload')->nullable();
            $table->timestamps();

            $table->unique(['principal_id', 'operation', 'idempotency_key_hash'], 'document_idempotency_scope_unique');
            $table->index(['resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_idempotency_keys');
        Schema::dropIfExists('document_quarantines');
        Schema::dropIfExists('document_upload_intents');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('document_storage_objects');
        Schema::dropIfExists('documents');
    }
};
