<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('record_number', 64)->unique();
            $table->uuid('work_type_version_id');
            $table->uuid('owner_facility_id');
            $table->uuid('creator_user_id');
            $table->string('status', 32)->index();
            $table->string('classification', 32)->index();
            $table->json('payload');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['owner_facility_id', 'status']);
        });

        Schema::create('work_record_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->uuid('principal_id');
            $table->uuid('facility_id');
            $table->string('operation', 96);
            $table->char('idempotency_key_hash', 64);
            $table->char('request_hash', 64);
            $table->uuid('work_record_id');
            $table->timestamps();

            $table->unique(
                ['principal_id', 'facility_id', 'operation', 'idempotency_key_hash'],
                'work_record_idempotency_scope_unique',
            );
            $table->index('work_record_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_record_idempotency_keys');
        Schema::dropIfExists('work_records');
    }
};
