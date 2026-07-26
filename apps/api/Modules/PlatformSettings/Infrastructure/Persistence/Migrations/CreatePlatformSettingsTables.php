<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_setting_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 16);
            $table->json('settings_document');
            $table->char('content_hash', 64);
            // Derived by the database so direct writes cannot bypass the one-published-version invariant.
            // A nullable unique guard works consistently in MySQL and SQLite.
            $table->string('published_slot', 16)
                ->storedAs("case when status = 'published' then 'published' else null end")
                ->unique();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->index(['status', 'published_at']);
        });

        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('platform_setting_version_id');
            $table->string('setting_key', 128);
            $table->json('setting_value');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['platform_setting_version_id', 'setting_key'], 'platform_settings_version_key_unique');
        });

        Schema::create('business_calendars', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope_type', 24);
            $table->uuid('scope_id')->nullable();
            $table->uuid('parent_calendar_id')->nullable();
            $table->string('status', 16);
            $table->string('timezone', 64)->default('Asia/Riyadh');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->index(['scope_type', 'scope_id', 'status'], 'business_calendars_scope_status_index');
            $table->index('parent_calendar_id');
        });

        Schema::create('business_calendar_weekdays', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_calendar_id');
            $table->unsignedTinyInteger('weekday');
            $table->boolean('is_working_day')->default(false);
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['business_calendar_id', 'weekday'], 'business_calendar_weekday_unique');
        });

        Schema::create('business_calendar_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_calendar_id');
            $table->string('exception_type', 48);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_official_holiday')->default(false);
            $table->boolean('is_working_day')->default(false);
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('reason', 1000)->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->index(['business_calendar_id', 'starts_on', 'ends_on'], 'business_calendar_exception_range_index');
        });

        Schema::create('platform_maintenance_windows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 16);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('reason', 1000);
            $table->uuid('created_by');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->index(['status', 'starts_at']);
        });

        Schema::create('platform_alert_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 96)->unique();
            $table->string('status', 16);
            $table->string('severity', 24);
            $table->string('channel', 32);
            $table->json('routing_policy');
            $table->json('escalation_policy')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->index(['status', 'severity']);
        });

        Schema::create('platform_operation_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('operation_type', 64);
            $table->string('status', 24);
            $table->uuid('requested_by');
            $table->uuid('confirmed_by')->nullable();
            $table->string('reason', 1000);
            $table->json('operation_payload')->nullable();
            $table->char('idempotency_key_hash', 64)->nullable();
            $table->string('dispatch_status', 24)->default('not_required');
            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->timestamp('dispatch_claimed_at')->nullable();
            $table->timestamp('dispatch_completed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->index(['operation_type', 'status']);
            $table->index(['operation_type', 'dispatch_status']);
            $table->unique(['requested_by', 'operation_type', 'idempotency_key_hash'], 'platform_operation_idempotency_unique');
        });

        Schema::create('platform_operation_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('operation_type', 64);
            $table->string('status', 24);
            $table->string('source', 96);
            $table->json('snapshot_payload');
            $table->timestamp('captured_at');
            $table->timestamps();
            $table->index(['operation_type', 'captured_at']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('platform_operation_snapshots');
        Schema::dropIfExists('platform_operation_requests');
        Schema::dropIfExists('platform_alert_policies');
        Schema::dropIfExists('platform_maintenance_windows');
        Schema::dropIfExists('business_calendar_exceptions');
        Schema::dropIfExists('business_calendar_weekdays');
        Schema::dropIfExists('business_calendars');
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('platform_setting_versions');
    }
};
