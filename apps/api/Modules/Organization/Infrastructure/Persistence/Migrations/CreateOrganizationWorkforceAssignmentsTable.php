<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('person_id')->constrained('people')->restrictOnDelete();
            $table->foreignUuid('position_id')->constrained('positions')->restrictOnDelete();
            $table->dateTime('start_at', 3);
            $table->dateTime('end_at', 3)->nullable();
            $table->boolean('is_primary')->default(true);
            $table->text('end_reason')->nullable();
            $table->uuid('ended_by_user_id')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['person_id', 'is_primary', 'start_at', 'end_at'], 'assignments_person_period_index');
            $table->index(['position_id', 'start_at', 'end_at'], 'assignments_position_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
