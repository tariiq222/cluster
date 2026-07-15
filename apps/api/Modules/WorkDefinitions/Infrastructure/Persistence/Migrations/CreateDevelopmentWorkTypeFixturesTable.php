<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        Schema::create('work_definition_development_work_type_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->unsignedSmallInteger('version');
            $table->string('status', 32);
            $table->json('input_schema');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_definition_development_work_type_versions');
    }
};
