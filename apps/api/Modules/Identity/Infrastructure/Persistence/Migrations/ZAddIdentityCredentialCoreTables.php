<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                if (! Schema::hasColumn('users', 'is_admin')) {
                    $table->boolean('is_admin')->default(false)->index();
                }
                if (! Schema::hasColumn('users', 'lockout_level')) {
                    $table->unsignedInteger('lockout_level')->default(0);
                }
            });
        }

        if (! Schema::hasTable('credentials')) {
            Schema::create('credentials', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->string('password_hash', 255);
                $table->string('hash_algorithm', 32);
                $table->timestamp('password_changed_at');
                $table->string('policy_version', 32);
                $table->timestamps();
                $table->index(['user_id', 'password_changed_at']);
            });
        }

        if (! Schema::hasTable('identity_password_history')) {
            Schema::create('identity_password_history', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('password_hash', 255);
                $table->string('hash_algorithm', 32);
                $table->unsignedBigInteger('password_version');
                $table->timestamps();
                $table->index(['user_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('identity_activation_tokens')) {
            Schema::create('identity_activation_tokens', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
                $table->char('token_hash', 64)->unique();
                $table->timestamp('expires_at')->index();
                $table->timestamp('used_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'used_at', 'expires_at']);
            });
        }

        if (! Schema::hasTable('identity_totp')) {
            Schema::create('identity_totp', function (Blueprint $table): void {
                $table->foreignUuid('user_id')->primary()->constrained('users')->cascadeOnDelete();
                $table->boolean('required')->default(false);
                $table->boolean('enabled')->default(false);
                $table->text('secret_ciphertext')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->unsignedBigInteger('last_used_step')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('identity_auth_attempt_ledgers')) {
            Schema::create('identity_auth_attempt_ledgers', function (Blueprint $table): void {
                $table->id();
                $table->string('scope', 32);
                $table->char('scope_hash', 64);
                $table->char('username_hash', 64);
                $table->timestamp('window_started_at');
                $table->unsignedInteger('attempt_count')->default(0);
                $table->unsignedInteger('lock_level')->default(0);
                $table->timestamp('blocked_until')->nullable()->index();
                $table->timestamp('last_attempt_at')->nullable();
                $table->timestamps();
                $table->unique(['scope', 'scope_hash'], 'identity_auth_attempt_scope_unique');
                $table->index(['username_hash', 'scope']);
            });
        }

        if (Schema::hasTable('identity_sessions')) {
            Schema::table('identity_sessions', function (Blueprint $table): void {
                if (! Schema::hasColumn('identity_sessions', 'csrf_token_hash')) {
                    $table->char('csrf_token_hash', 64)->nullable();
                }
                if (! Schema::hasColumn('identity_sessions', 'mfa_verified')) {
                    $table->boolean('mfa_verified')->default(false);
                }
            });
        }
    }

    public function down(): void
    {
        $credentialTables = [
            'credentials',
            'identity_password_history',
            'identity_activation_tokens',
            'identity_totp',
            'identity_auth_attempt_ledgers',
        ];
        foreach ($credentialTables as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new \LogicException('identity_credentials_rollback_requires_empty_tables');
            }
        }

        Schema::dropIfExists('identity_auth_attempt_ledgers');
        Schema::dropIfExists('identity_totp');
        Schema::dropIfExists('identity_activation_tokens');
        Schema::dropIfExists('identity_password_history');
        Schema::dropIfExists('credentials');

        if (Schema::hasTable('identity_sessions')) {
            $columns = array_values(array_filter(
                ['csrf_token_hash', 'mfa_verified'],
                static fn (string $column): bool => Schema::hasColumn('identity_sessions', $column),
            ));
            if ($columns !== []) {
                Schema::table('identity_sessions', static function (Blueprint $table) use ($columns): void {
                    $table->dropColumn($columns);
                });
            }
        }
        if (Schema::hasTable('users')) {
            if (Schema::hasIndex('users', ['is_admin'])) {
                Schema::table('users', static function (Blueprint $table): void {
                    $table->dropIndex(['is_admin']);
                });
            }
            $columns = array_values(array_filter(
                ['is_admin', 'lockout_level'],
                static fn (string $column): bool => Schema::hasColumn('users', $column),
            ));
            if ($columns !== []) {
                Schema::table('users', static function (Blueprint $table) use ($columns): void {
                    $table->dropColumn($columns);
                });
            }
        }
    }
};
