<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('explicit_denies', function (Blueprint $table): void {
            $table->unsignedInteger('lock_version')->default(1)->after('revocable');
        });
    }

    public function down(): void
    {
        Schema::table('explicit_denies', function (Blueprint $table): void {
            $table->dropColumn('lock_version');
        });
    }
};
