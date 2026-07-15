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
    }

    public function down(): void
    {
        Schema::dropIfExists('work_records');
    }
};
