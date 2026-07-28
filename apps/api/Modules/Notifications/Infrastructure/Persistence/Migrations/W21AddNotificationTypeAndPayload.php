<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table): void {
            if (! Schema::hasColumn('notifications', 'type')) {
                $table->string('type', 64)->nullable()->after('recipient_user_id');
            }
            if (! Schema::hasColumn('notifications', 'payload')) {
                $table->json('payload')->nullable()->after('last_event_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['type', 'payload'],
                static fn (string $column): bool => Schema::hasColumn('notifications', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
