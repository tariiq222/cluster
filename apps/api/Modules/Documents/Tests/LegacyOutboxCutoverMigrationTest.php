<?php

declare(strict_types=1);

namespace Modules\Documents\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class LegacyOutboxCutoverMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cutover_copies_verifies_drops_replays_and_restores_legacy_outboxes(): void
    {
        $this->createDocumentOutbox();
        $this->createPlatformOutbox();
        $now = now()->startOfSecond();
        $documentEventId = '018f6f7d-0c00-7000-8000-000000000911';
        $platformEventId = '018f6f7d-0c00-7000-8000-000000000912';
        DB::table('document_outbox_events')->insert([
            'id' => $documentEventId,
            'aggregate_id' => '018f6f7d-0c00-7000-8000-000000000913',
            'event_type' => 'com.cluster.documents.uploadinitiated.v1',
            'payload' => json_encode(['document_id' => 'document-1'], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'published_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('platform_settings_outbox')->insert([
            'id' => $platformEventId,
            'event_type' => 'com.cluster.platform.technical-alert.v1',
            'aggregate_type' => 'technical_alert',
            'aggregate_id' => '018f6f7d-0c00-7000-8000-000000000914',
            'payload' => json_encode(['severity' => 'critical'], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $migration = require base_path('Shared/Infrastructure/Outbox/Migrations/MigrateLegacyModuleOutboxes.php');
        $migration->up();

        $this->assertFalse(Schema::hasTable('document_outbox_events'));
        $this->assertFalse(Schema::hasTable('platform_settings_outbox'));
        $this->assertDatabaseHas('outbox_events', ['event_id' => $documentEventId, 'event_type' => 'com.cluster.documents.uploadinitiated.v1']);
        $this->assertDatabaseHas('outbox_events', ['event_id' => $platformEventId, 'event_type' => 'com.cluster.platform.technical-alert.v1']);
        $documentEnvelope = json_decode((string) DB::table('outbox_events')->where('event_id', $documentEventId)->value('cloud_event'), true, 512, JSON_THROW_ON_ERROR);
        $platformEnvelope = json_decode((string) DB::table('outbox_events')->where('event_id', $platformEventId)->value('cloud_event'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('document-1', $documentEnvelope['data']['document_id']);
        $this->assertSame('technical_alert', $platformEnvelope['aggregatetype']);
        $this->assertSame('critical', $platformEnvelope['data']['severity']);
        $this->assertNotNull(DB::table('outbox_events')->where('event_id', $platformEventId)->value('published_at'));

        $migration->up();
        $this->assertSame(2, DB::table('outbox_events')->whereIn('event_id', [$documentEventId, $platformEventId])->count());

        $migration->down();
        $this->assertTrue(Schema::hasTable('document_outbox_events'));
        $this->assertTrue(Schema::hasTable('platform_settings_outbox'));
        $this->assertDatabaseHas('document_outbox_events', ['id' => $documentEventId]);
        $this->assertDatabaseHas('platform_settings_outbox', ['id' => $platformEventId, 'aggregate_type' => 'technical_alert']);

        $migration->up();
    }

    public function test_cutover_rejects_conflicting_event_ids_without_dropping_legacy_rows(): void
    {
        $this->createDocumentOutbox();
        $now = now()->startOfSecond();
        $eventId = '018f6f7d-0c00-7000-8000-000000000921';
        $aggregateId = '018f6f7d-0c00-7000-8000-000000000922';
        DB::table('outbox_events')->insert([
            'event_id' => $eventId,
            'aggregate_id' => $aggregateId,
            'event_type' => 'com.cluster.documents.uploadinitiated.v1',
            'cloud_event' => json_encode(['type' => 'com.cluster.documents.uploadinitiated.v1', 'data' => ['document_id' => 'shared']], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'published_at' => null,
            'delivery_attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('document_outbox_events')->insert([
            'id' => $eventId,
            'aggregate_id' => $aggregateId,
            'event_type' => 'com.cluster.documents.uploadinitiated.v1',
            'payload' => json_encode(['document_id' => 'legacy-conflict'], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'published_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            (require base_path('Shared/Infrastructure/Outbox/Migrations/MigrateLegacyModuleOutboxes.php'))->up();
            $this->fail('Conflicting legacy event content must abort the cutover.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('conflicts with the shared outbox row', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('document_outbox_events'));
        $this->assertDatabaseHas('document_outbox_events', ['id' => $eventId]);
    }

    public function test_restore_rejects_canonical_events_without_object_data_before_creating_legacy_tables(): void
    {
        $now = now()->startOfSecond();
        $eventId = '018f6f7d-0c00-7000-8000-000000000923';
        DB::table('outbox_events')->insert([
            'event_id' => $eventId,
            'aggregate_id' => '018f6f7d-0c00-7000-8000-000000000924',
            'event_type' => 'com.cluster.documents.uploadinitiated.v1',
            'cloud_event' => json_encode(['type' => 'com.cluster.documents.uploadinitiated.v1'], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'published_at' => null,
            'delivery_attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            (require base_path('Shared/Infrastructure/Outbox/Migrations/MigrateLegacyModuleOutboxes.php'))->down();
            $this->fail('Canonical events without object data must abort legacy restoration.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('data must be a JSON object', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasTable('document_outbox_events'));
        $this->assertFalse(Schema::hasTable('platform_settings_outbox'));
        $this->assertDatabaseHas('outbox_events', ['event_id' => $eventId]);
    }

    private function createDocumentOutbox(): void
    {
        Schema::create('document_outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('aggregate_id')->index();
            $table->string('event_type', 128)->index();
            $table->json('payload');
            $table->dateTime('occurred_at', 3);
            $table->dateTime('published_at', 3)->nullable();
            $table->timestamps();
            $table->index(['published_at', 'occurred_at']);
        });
    }

    private function createPlatformOutbox(): void
    {
        Schema::create('platform_settings_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type', 128);
            $table->string('aggregate_type', 96);
            $table->uuid('aggregate_id');
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['published_at', 'occurred_at']);
        });
    }
}
