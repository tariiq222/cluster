<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name_ar');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('organization_units', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cluster_id')->constrained('clusters')->restrictOnDelete();
            $table->uuid('parent_id');
            $table->string('parent_type', 16);
            $table->foreignUuid('unit_type_id')->constrained('unit_types')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->string('path_cache', 512);
            $table->unsignedSmallInteger('depth');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['parent_type', 'parent_id', 'code'], 'organization_unit_parent_code_unique');
            $table->index(['cluster_id', 'status']);
            $table->index(['parent_type', 'parent_id']);
            $table->index('path_cache');
        });

        Schema::create('positions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('title_ar');
            $table->uuid('manager_position_id')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['organization_unit_id', 'code']);
            $table->foreign('manager_position_id')->references('id')->on('positions')->restrictOnDelete();
            $table->index(['organization_unit_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
        Schema::dropIfExists('organization_units');
        Schema::dropIfExists('unit_types');
    }
};
