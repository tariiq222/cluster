<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'mfa_required')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('mfa_required')->default(false)->after('is_admin');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'mfa_required')) {
            Schema::table('users', static function (Blueprint $table): void {
                $table->dropColumn('mfa_required');
            });
        }
    }
};
