<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('work_records') && ! Schema::hasColumn('work_records', 'field_policy_key')) {
            Schema::table('work_records', function (Blueprint $table): void {
                $table->string('field_policy_key', 128)->nullable()->after('classification');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('work_records') && Schema::hasColumn('work_records', 'field_policy_key')) {
            Schema::table('work_records', function (Blueprint $table): void {
                $table->dropColumn('field_policy_key');
            });
        }
    }
};
