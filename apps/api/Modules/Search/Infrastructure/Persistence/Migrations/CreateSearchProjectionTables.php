<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_index_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_module', 64);
            $table->string('source_type', 96);
            $table->string('source_id', 128);
            $table->string('source_version', 64);
            $table->string('projection_version', 32);
            $table->uuid('scope_id')->nullable();
            $table->string('classification', 24)->default('internal');
            $table->string('visibility', 16)->default('eligible');
            $table->string('title', 240)->nullable();
            $table->string('excerpt', 500)->nullable();
            $table->text('search_text')->nullable();
            $table->timestamps(3);

            $table->unique(
                ['source_type', 'source_id', 'projection_version'],
                'search_source_projection_unique',
            );
            $table->index(['scope_id', 'visibility'], 'search_scope_visibility_index');
            $table->index(['source_type', 'source_version'], 'search_source_version_index');
        });

        Schema::create('search_checkpoints', function (Blueprint $table): void {
            $table->string('consumer', 96)->primary();
            $table->string('checkpoint', 128)->nullable();
            $table->string('projection_version', 32);
            $table->timestamps(3);
        });

        Schema::create('search_inbox', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 128);
            $table->timestamps(3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_inbox');
        Schema::dropIfExists('search_checkpoints');
        Schema::dropIfExists('search_index_entries');
    }
};
