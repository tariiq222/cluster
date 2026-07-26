<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_inbox')) {
            Schema::table('notification_inbox', function (Blueprint $table): void {
                if (! Schema::hasColumn('notification_inbox', 'recipient_capability')) {
                    $table->string('recipient_capability', 96)->nullable();
                }
                if (! Schema::hasColumn('notification_inbox', 'consumer')) {
                    $table->string('consumer', 96)->nullable();
                }
            });
        }

        if (! Schema::hasTable('notifications')) {
            return;
        }

        $legacyEventUnique = array_values(array_filter(
            Schema::getIndexes('notifications'),
            static fn (array $index): bool => $index['unique']
                && $index['columns'] === ['event_id'],
        ));
        foreach ($legacyEventUnique as $index) {
            Schema::table('notifications', static function (Blueprint $table) use ($index): void {
                $table->dropUnique($index['name']);
            });
        }

        if (! Schema::hasIndex('notifications', ['event_id', 'recipient_user_id'], 'unique')) {
            Schema::table('notifications', static function (Blueprint $table): void {
                $table->unique(['event_id', 'recipient_user_id'], 'notifications_event_recipient_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notifications')) {
            $compositeUniques = array_values(array_filter(
                Schema::getIndexes('notifications'),
                static fn (array $index): bool => $index['unique']
                    && $index['columns'] === ['event_id', 'recipient_user_id'],
            ));
            foreach ($compositeUniques as $index) {
                Schema::table('notifications', static function (Blueprint $table) use ($index): void {
                    $table->dropUnique($index['name']);
                });
            }
        }

        if (Schema::hasTable('notifications')
            && ! Schema::hasIndex('notifications', ['event_id'], 'unique')) {
            Schema::table('notifications', static function (Blueprint $table): void {
                $table->unique('event_id', 'notifications_event_id_unique');
            });
        }

        if (Schema::hasTable('notification_inbox')
            && Schema::hasColumn('notification_inbox', 'recipient_capability')) {
            Schema::table('notification_inbox', static function (Blueprint $table): void {
                $table->dropColumn('recipient_capability');
            });
        }
    }
};
