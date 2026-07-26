<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->uuid('principal_id');
            $table->string('operation', 128);
            $table->char('idempotency_key_hash', 64);
            $table->char('request_hash', 64);
            $table->json('response_payload')->nullable();
            $table->timestamps(3);
            $table->unique(
                ['principal_id', 'operation', 'idempotency_key_hash'],
                'platform_settings_idempotency_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings_idempotency_keys');
    }
};
