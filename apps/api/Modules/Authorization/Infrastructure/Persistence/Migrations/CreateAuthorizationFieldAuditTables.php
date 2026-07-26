<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classification_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('classification_code', 32)->unique();
            $table->string('minimum_capability', 96);
            $table->string('export_policy', 32);
            $table->string('download_policy', 32);
            $table->string('policy_version', 32);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'classification_code']);
        });

        Schema::create('field_access_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('field_policy_key', 128)->unique();
            $table->string('module_code', 64);
            $table->json('policy_definition');
            $table->string('policy_version', 32);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['module_code', 'is_active']);
        });

        Schema::create('sensitive_access_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('access_decision_id')->nullable();
            $table->uuid('actor_user_id');
            $table->uuid('original_actor_user_id');
            $table->string('resource_type', 64);
            $table->uuid('resource_id');
            $table->string('action', 64);
            $table->string('classification_code', 32);
            $table->uuid('correlation_id');
            $table->string('source_ip', 45)->nullable();
            $table->char('device_fingerprint_hash', 64)->nullable();
            $table->char('idempotency_key_hash', 64);
            $table->timestamp('occurred_at');
            $table->timestamp('recorded_at')->useCurrent();

            $table->index(['actor_user_id', 'occurred_at']);
            $table->index(['resource_type', 'resource_id', 'occurred_at'], 'sensitive_access_events_resource_occurred_index');
            $table->index('correlation_id');
            $table->index('idempotency_key_hash', 'sensitive_access_events_idempotency_hash_index');
        });

        $this->addSensitiveAccessEventAppendOnlyGuards();
    }

    public function down(): void
    {
        Schema::dropIfExists('sensitive_access_events');
        Schema::dropIfExists('field_access_templates');
        Schema::dropIfExists('classification_policies');
    }

    private function addSensitiveAccessEventAppendOnlyGuards(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $name => $operation) {
                DB::unprepared(<<<SQL
                    CREATE TRIGGER sensitive_access_events_{$name}_prevent
                    BEFORE {$operation} ON sensitive_access_events
                    FOR EACH ROW
                    BEGIN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'sensitive_access_events_append_only';
                    END
                    SQL);
            }

            return;
        }

        if ($driver !== 'sqlite') {
            return;
        }

        foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $name => $operation) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER sensitive_access_events_{$name}_prevent
                BEFORE {$operation} ON sensitive_access_events
                BEGIN
                    SELECT RAISE(ABORT, 'sensitive_access_events_append_only');
                END
                SQL);
        }
    }
};
