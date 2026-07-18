<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['roles', 'capabilities', 'role_assignments', 'delegations', 'classification_policies', 'field_access_templates'] as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'lock_version')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->unsignedInteger('lock_version')->default(1)->after('updated_at');
                });
            }
        }
        Schema::create('authorization_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->uuid('principal_id');
            $table->string('operation', 128);
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->string('resource_id', 64);
            $table->unsignedSmallInteger('response_status')->default(200);
            $table->json('response_payload');
            $table->timestamps(3);
            $table->unique(['principal_id', 'operation', 'key_hash'], 'authorization_idempotency_unique');
        });
        Schema::create('access_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('decision', 8);
            $table->string('action', 128);
            $table->string('resource_type', 128);
            $table->uuid('resource_id')->nullable();
            $table->json('reason_codes');
            $table->string('policy_version', 128);
            $table->string('facts_version', 128);
            $table->uuid('authorization_trace_id');
            $table->dateTime('evaluated_at', 3);
            $table->uuid('correlation_id');
            $table->string('classification', 32);
            $table->json('access_context');
            $table->uuid('actor_user_id');
            $table->timestamps(3);
            $table->index(['actor_user_id', 'evaluated_at'], 'access_decisions_actor_time_index');
            $table->index(['resource_type', 'resource_id'], 'access_decisions_resource_index');
            $table->index('correlation_id', 'access_decisions_correlation_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_decisions');
        Schema::dropIfExists('authorization_idempotency_keys');
        foreach (['field_access_templates', 'classification_policies', 'delegations', 'role_assignments', 'capabilities', 'roles'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'lock_version')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropColumn('lock_version');
                });
            }
        }
    }
};
