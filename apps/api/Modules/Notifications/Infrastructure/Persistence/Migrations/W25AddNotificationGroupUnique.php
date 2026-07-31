<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'notif_recipient_group_uq_w25';

    private const LEGACY_INDEX = 'notif_recipient_group_idx';

    public function up(): void
    {
        if (! Schema::hasTable('notifications')
            || Schema::hasIndex(
                'notifications',
                ['recipient_user_id', 'notification_group_key'],
                'unique',
            )) {
            return;
        }

        $duplicatesExist = DB::table('notifications')
            ->select('recipient_user_id', 'notification_group_key')
            ->whereNotNull('notification_group_key')
            ->groupBy('recipient_user_id', 'notification_group_key')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicatesExist) {
            throw new LogicException('notifications_recipient_group_unique_requires_duplicate_remediation');
        }

        Schema::table('notifications', static function (Blueprint $table): void {
            $table->dropIndex(self::LEGACY_INDEX);
            $table->unique(['recipient_user_id', 'notification_group_key'], self::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $createdByThisMigration = array_filter(
            Schema::getIndexes('notifications'),
            static fn (array $index): bool => $index['name'] === self::UNIQUE_INDEX,
        );
        if ($createdByThisMigration !== []) {
            Schema::table('notifications', static function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE_INDEX);
            });
        }

        if (! Schema::hasIndex('notifications', ['recipient_user_id', 'notification_group_key'])) {
            Schema::table('notifications', static function (Blueprint $table): void {
                $table->index(['recipient_user_id', 'notification_group_key'], self::LEGACY_INDEX);
            });
        }
    }
};
