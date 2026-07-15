<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FACILITY_A_ID = '018f6f7d-0c00-7000-8000-000000000011';

    private const FACILITY_B_ID = '018f6f7d-0c00-7000-8000-000000000012';

    public function up(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        Schema::create('identity_development_fixture_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('username')->unique();
            $table->string('password_hash');
            $table->uuid('facility_id');
            $table->timestamps();
        });

        DB::table('identity_development_fixture_accounts')->insert([
            [
                'id' => '018f6f7d-0c00-7000-8000-000000000021',
                'username' => 'fixture-account-a',
                'password_hash' => Hash::make('fixture-password-a'),
                'facility_id' => self::FACILITY_A_ID,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => '018f6f7d-0c00-7000-8000-000000000022',
                'username' => 'fixture-account-b',
                'password_hash' => Hash::make('fixture-password-b'),
                'facility_id' => self::FACILITY_B_ID,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_development_fixture_accounts');
    }
};
