<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_runs', function (Blueprint $table): void {
            $table->char('idempotency_key_hash', 64)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->unique(['actor_id', 'idempotency_key_hash'], 'report_runs_actor_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('report_runs', function (Blueprint $table): void {
            $table->dropUnique('report_runs_actor_idempotency_unique');
            $table->dropColumn(['idempotency_key_hash', 'request_hash']);
        });
    }
};
