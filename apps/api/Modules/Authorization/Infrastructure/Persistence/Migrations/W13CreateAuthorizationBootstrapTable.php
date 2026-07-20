<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorization_bootstrap', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('state', 16)->default('pending')->index();
            $table->uuid('completed_by_user_id')->nullable();
            $table->dateTime('completed_at', 3)->nullable();
            $table->string('reason', 500)->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps(3);
        });

        if (DB::table('authorization_bootstrap')->count() === 0) {
            DB::table('authorization_bootstrap')->insert([
                'id' => Str::uuid7()->toString(),
                'state' => 'pending',
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('authorization_bootstrap');
    }
};
