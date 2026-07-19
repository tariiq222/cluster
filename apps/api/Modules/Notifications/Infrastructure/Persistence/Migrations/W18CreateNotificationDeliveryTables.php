<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_inbox', function (Blueprint $table): void {
            $table->string('consumer', 96)->default('notifications')->after('event_id');
            $table->index(['consumer', 'processed_at'], 'notif_inbox_consumer_idx');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('status', 16)->default('unread')->after('is_read');
            $table->string('notification_group_key', 191)->nullable()->after('source_record_id');
            $table->unsignedInteger('aggregation_count')->default(1)->after('notification_group_key');
            $table->uuid('last_event_id')->nullable()->after('aggregation_count');
            $table->index(['recipient_user_id', 'notification_group_key'], 'notif_recipient_group_idx');
        });

        Schema::create('notification_recipients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('notification_id');
            $table->uuid('recipient_user_id');
            $table->string('status', 16)->default('unread');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['notification_id', 'recipient_user_id'], 'notif_recipient_uq');
            $table->index(['recipient_user_id', 'status'], 'notif_recipient_status_idx');
        });

        Schema::create('notification_dead_letters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_stream', 128);
            $table->string('source_message_id', 128);
            $table->json('original_event');
            $table->string('failure_code', 64);
            $table->unsignedInteger('attempts');
            $table->string('consumer', 96);
            $table->timestamp('failed_at');
            $table->timestamp('replayed_at')->nullable();
            $table->timestamps();

            $table->unique(['source_stream', 'source_message_id'], 'notif_dlq_source_uq');
            $table->index(['failure_code', 'failed_at'], 'notif_dlq_failure_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dead_letters');
        Schema::dropIfExists('notification_recipients');
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notif_recipient_group_idx');
            $table->dropColumn(['status', 'notification_group_key', 'aggregation_count', 'last_event_id']);
        });
        Schema::table('notification_inbox', function (Blueprint $table): void {
            $table->dropIndex('notif_inbox_consumer_idx');
            $table->dropColumn('consumer');
        });
    }
};
