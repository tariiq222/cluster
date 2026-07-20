<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_participants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id')->index();
            $table->uuid('user_id');
            $table->string('role', 64)->default('participant');
            $table->uuid('added_by_user_id');
            $table->timestamps();
            $table->unique(['task_id', 'user_id']);
        });

        Schema::create('task_comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id')->index();
            $table->uuid('author_user_id');
            $table->text('body');
            $table->json('mentioned_user_ids')->nullable();
            $table->timestamp('created_at', 3);
            $table->index(['task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('task_participants');
    }
};
