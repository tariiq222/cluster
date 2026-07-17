<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clusters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedTinyInteger('singleton_key')->default(1)->unique();
            $table->string('code', 64)->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });

        Schema::create('facility_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name_ar');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('facilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cluster_id')->constrained('clusters')->restrictOnDelete();
            $table->foreignUuid('facility_type_id')->constrained('facility_types')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['cluster_id', 'code']);
            $table->index(['cluster_id', 'status']);
        });

        Schema::create('organization_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->uuid('principal_id');
            $table->string('operation', 96);
            $table->char('idempotency_key_hash', 64);
            $table->char('request_hash', 64);
            $table->string('resource_type', 32);
            $table->uuid('resource_id');
            $table->json('response_payload')->nullable();
            $table->timestamps();

            $table->unique(
                ['principal_id', 'operation', 'idempotency_key_hash'],
                'organization_idempotency_scope_unique',
            );
            $table->index(['resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_idempotency_keys');
        Schema::dropIfExists('facilities');
        Schema::dropIfExists('facility_types');
        Schema::dropIfExists('clusters');
    }
};
