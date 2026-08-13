<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `search_inbox` was created with the projection tables but no production
 * code ever wrote to or read from it, so the event inbox is vestigial. The
 * down() path restores the original schema for rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('search_inbox');
    }

    public function down(): void
    {
        Schema::create('search_inbox', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 128);
            $table->timestamps(3);
        });
    }
};
