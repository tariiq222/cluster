<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_links')) {
            return;
        }

        Schema::table('document_links', function (Blueprint $table): void {
            if (! Schema::hasColumn('document_links', 'constraint_policy_key')) {
                $table->string('constraint_policy_key', 128)->nullable()->after('relation_type');
            }
            $table->string('relation_type', 64)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('document_links')) {
            return;
        }

        Schema::table('document_links', function (Blueprint $table): void {
            if (Schema::hasColumn('document_links', 'constraint_policy_key')) {
                $table->dropColumn('constraint_policy_key');
            }
            $table->string('relation_type', 32)->change();
        });
    }
};
