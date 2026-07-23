<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Notifications\Contracts\ResolveTechnicalAlertRecipients;
use Modules\Notifications\Features\ConsumeTechnicalAlert\Handler\ConsumeTechnicalAlertHandler;
use Tests\TestCase;

final class TechnicalAlertForwardMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_forward_migration_upgrades_the_old_schema_for_idempotent_technical_alert_fan_out(): void
    {
        Schema::dropIfExists('notification_recipients');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_inbox');
        Schema::create('notification_inbox', function ($table): void {
            $table->uuid('event_id')->primary();
            $table->timestamp('processed_at');
            $table->timestamps();
        });
        Schema::create('notifications', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->unique();
            $table->uuid('recipient_user_id')->index();
            $table->string('title', 160);
            $table->uuid('source_record_id');
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });

        $migration = require base_path('Modules/Notifications/Infrastructure/Persistence/Migrations/W20UpgradeTechnicalAlertFanoutSchema.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumns('notification_inbox', ['recipient_capability', 'consumer']));
        $this->assertTrue(Schema::hasIndex('notifications', ['event_id', 'recipient_user_id'], 'unique'));
        $this->assertFalse(Schema::hasIndex('notifications', ['event_id'], 'unique'));

        $this->app->instance(ResolveTechnicalAlertRecipients::class, new class implements ResolveTechnicalAlertRecipients
        {
            public function resolve(string $recipientCapability): array
            {
                return [
                    '019f8e3b-3368-7192-85a6-3da3949fd771',
                    '019f8e3b-3368-7192-85a6-3da3949fd772',
                ];
            }
        });
        $handler = $this->app->make(ConsumeTechnicalAlertHandler::class);
        $event = [
            'specversion' => '1.0',
            'id' => '019f8e3b-3368-7192-85a6-3da3949fd770',
            'source' => '/platform-settings',
            'type' => 'com.cluster.platform.technical-alert.v1',
            'subject' => '/technical-alerts/database-latency',
            'time' => '2026-07-23T07:15:00.000Z',
            'datacontenttype' => 'application/json',
            'data' => [
                'alert_code' => 'database-latency',
                'severity' => 'critical',
                'recipient_capability' => 'platform_operations.alerts.manage',
                'occurred_at' => '2026-07-23T07:15:00+00:00',
                'correlation_id' => '019f8e3b-3368-7192-85a6-3da3949fd75a',
            ],
        ];

        $this->assertTrue($handler->handle($event));
        $this->assertFalse($handler->handle($event));
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('notification_inbox', [
            'event_id' => $event['id'],
            'recipient_capability' => 'platform_operations.alerts.manage',
            'consumer' => 'notifications.technical-alert.v1',
        ]);
    }
}
