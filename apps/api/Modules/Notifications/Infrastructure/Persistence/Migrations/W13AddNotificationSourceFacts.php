<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->uuid('source_owner_facility_id')->nullable()->after('source_record_id');
            $table->string('source_classification', 32)->nullable()->after('source_owner_facility_id');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn(['source_owner_facility_id', 'source_classification']);
        });
    }
};
