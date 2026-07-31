<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('search_index_entries')
            || Schema::hasColumn('search_index_entries', 'status')) {
            return;
        }

        Schema::table('search_index_entries', function (Blueprint $table): void {
            $table->string('status', 32)->nullable()->after('source_version');
            $table->index(['source_type', 'status'], 'search_type_status_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('search_index_entries')
            || ! Schema::hasColumn('search_index_entries', 'status')) {
            return;
        }

        Schema::table('search_index_entries', function (Blueprint $table): void {
            $table->dropIndex('search_type_status_index');
            $table->dropColumn('status');
        });
    }
};
