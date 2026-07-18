<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('document_upload_intents', 'purpose')) {
            Schema::table('document_upload_intents', function (Blueprint $table): void {
                $table->string('purpose', 64)->nullable()->after('storage_object_id');
            });
        }

        DB::table('document_upload_intents')->whereNull('purpose')->update([
            'purpose' => 'document_version',
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('document_upload_intents', 'purpose')) {
            Schema::table('document_upload_intents', function (Blueprint $table): void {
                $table->dropColumn('purpose');
            });
        }
    }
};
