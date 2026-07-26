<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('username', 128)->unique();
            $table->uuid('person_id')->nullable()->index();
            $table->unsignedBigInteger('person_version')->nullable();
            $table->string('display_name_ar');
            $table->string('display_name_en')->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->boolean('must_change_password')->default(true);
            $table->unsignedBigInteger('password_version')->default(1);
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedInteger('failed_login_count')->default(0);
            $table->timestamp('locked_until')->nullable()->index();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });

        Schema::create('identity_person_account_claims', function (Blueprint $table): void {
            $table->uuid('person_id')->primary();
            $table->foreignUuid('account_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('identity_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->unsignedBigInteger('password_version');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata');
            $table->timestamps();
            $table->index(['user_id', 'revoked_at', 'expires_at']);
        });

        Schema::create('identity_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->uuid('principal_id');
            $table->string('operation', 160);
            $table->char('idempotency_key_hash', 64);
            $table->char('request_hash', 64);
            $table->string('resource_type', 32);
            $table->uuid('resource_id');
            $table->json('response_payload')->nullable();
            $table->unsignedInteger('response_version')->nullable();
            $table->timestamps();
            $table->unique(['principal_id', 'operation', 'idempotency_key_hash'], 'identity_idempotency_scope_unique');
            $table->index(['resource_type', 'resource_id']);
        });

        Schema::create('identity_inbox', function (Blueprint $table): void {
            $table->uuid('event_id')->primary();
            $table->string('event_type', 128);
            $table->uuid('person_id');
            $table->unsignedBigInteger('person_version');
            $table->timestamp('processed_at');
            $table->timestamps();
            $table->index(['person_id', 'person_version']);
        });

        Schema::create('identity_person_event_watermarks', function (Blueprint $table): void {
            $table->uuid('person_id')->primary();
            $table->unsignedBigInteger('last_person_version');
            $table->uuid('last_event_id');
            $table->string('last_event_type', 128);
            $table->timestamps();
        });

        Schema::create('identity_person_provisioning', function (Blueprint $table): void {
            $table->uuid('person_id')->primary();
            $table->unsignedBigInteger('person_version');
            $table->string('requested_account_status', 16);
            $table->uuid('last_event_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Credential migrations must roll back first. Do not disable foreign
        // keys here: an out-of-order rollback must fail rather than orphan
        // surviving credential constraints that still reference users.
        $credentialTables = [
            'credentials',
            'identity_password_history',
            'identity_activation_tokens',
            'identity_totp',
            'identity_auth_attempt_ledgers',
        ];
        foreach ($credentialTables as $credentialTable) {
            if (Schema::hasTable($credentialTable)) {
                throw new \LogicException('identity_credentials_must_rollback_first');
            }
        }

        Schema::dropIfExists('identity_person_provisioning');
        Schema::dropIfExists('identity_person_event_watermarks');
        Schema::dropIfExists('identity_inbox');
        Schema::dropIfExists('identity_idempotency_keys');
        Schema::dropIfExists('identity_sessions');
        Schema::dropIfExists('identity_person_account_claims');
        Schema::dropIfExists('users');
    }
};
