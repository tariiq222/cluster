<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_inbox', function (Blueprint $table): void {
            $table->uuid('event_id')->primary();
            $table->timestamp('processed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_inbox');
    }
};
