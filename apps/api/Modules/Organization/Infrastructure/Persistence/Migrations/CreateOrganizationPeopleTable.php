<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->binary('national_id_ciphertext')->nullable();
            $table->char('national_id_lookup_hash', 64)->nullable()->unique();
            $table->string('employee_number', 64)->unique();
            $table->string('display_name_ar');
            $table->string('display_name_en')->nullable();
            $table->binary('primary_email_ciphertext')->nullable();
            $table->binary('primary_phone_ciphertext')->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->unsignedBigInteger('person_version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
