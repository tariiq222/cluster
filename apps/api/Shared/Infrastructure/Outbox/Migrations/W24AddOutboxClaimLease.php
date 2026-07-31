<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CLAIM_OWNER_INDEX = 'outbox_events_claim_owner_idx';

    private const LEASE_INDEX = 'outbox_events_lease_idx';

    public function up(): void
    {
        if (! Schema::hasTable('outbox_events')) {
            return;
        }

        if (! Schema::hasColumn('outbox_events', 'claim_owner')) {
            Schema::table('outbox_events', static function (Blueprint $table): void {
                $table->string('claim_owner', 96)->nullable();
                $table->timestamp('lease_expires_at')->nullable();
            });
        }

        Schema::table('outbox_events', static function (Blueprint $table): void {
            $table->index(['published_at', 'lease_expires_at'], self::LEASE_INDEX);
            $table->index(['claim_owner', 'lease_expires_at'], self::CLAIM_OWNER_INDEX);
        });

        // Backfill: rows that the previous delivery_attempts-only claim
        // marked as in-flight are already orphaned by definition (a crash
        // between claim and XADD is exactly what this migration repairs).
        // Operators may run a one-time script after deploy to reset those
        // rows; we do not auto-publish so we never lose events silently.
    }

    public function down(): void
    {
        if (! Schema::hasTable('outbox_events')) {
            return;
        }

        $indexes = Schema::getIndexes('outbox_events');
        foreach ([self::CLAIM_OWNER_INDEX, self::LEASE_INDEX] as $name) {
            $exists = array_filter(
                $indexes,
                static fn (array $index): bool => $index['name'] === $name,
            );
            if ($exists !== []) {
                Schema::table('outbox_events', static function (Blueprint $table) use ($name): void {
                    $table->dropIndex($name);
                });
            }
        }

        if (Schema::hasColumn('outbox_events', 'lease_expires_at')
            || Schema::hasColumn('outbox_events', 'claim_owner')) {
            Schema::table('outbox_events', static function (Blueprint $table): void {
                $table->dropColumn(['claim_owner', 'lease_expires_at']);
            });
        }
    }
};
