<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_record_idempotency_keys')
            || Schema::hasColumn('work_record_idempotency_keys', 'response_payload')) {
            return;
        }

        Schema::table('work_record_idempotency_keys', function (Blueprint $table): void {
            $table->json('response_payload')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('work_record_idempotency_keys')
            || ! Schema::hasColumn('work_record_idempotency_keys', 'response_payload')) {
            return;
        }

        Schema::table('work_record_idempotency_keys', function (Blueprint $table): void {
            $table->dropColumn('response_payload');
        });
    }
};
