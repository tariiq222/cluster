<?php

namespace Modules\Authorization\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthorizationFieldAuditMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('classification_policies')) {
            $migration = require dirname(__DIR__).'/Infrastructure/Persistence/Migrations/CreateAuthorizationFieldAuditTables.php';
            $migration->up();
        }
    }

    public function test_authorization_owns_classification_field_and_sensitive_audit_tables_without_foreign_keys(): void
    {
        $this->assertTrue(Schema::hasColumns('classification_policies', [
            'id',
            'classification_code',
            'minimum_capability',
            'export_policy',
            'download_policy',
            'policy_version',
            'is_active',
        ]));
        $this->assertTrue(Schema::hasColumns('field_access_templates', [
            'id',
            'field_policy_key',
            'module_code',
            'policy_definition',
            'policy_version',
            'is_active',
        ]));
        $this->assertTrue(Schema::hasColumns('sensitive_access_events', [
            'id',
            'access_decision_id',
            'actor_user_id',
            'original_actor_user_id',
            'resource_type',
            'resource_id',
            'action',
            'classification_code',
            'correlation_id',
            'source_ip',
            'device_fingerprint_hash',
            'idempotency_key_hash',
            'occurred_at',
        ]));

        $this->assertSame([], Schema::getForeignKeys('classification_policies'));
        $this->assertSame([], Schema::getForeignKeys('field_access_templates'));
        $this->assertSame([], Schema::getForeignKeys('sensitive_access_events'));
    }

    public function test_sensitive_access_events_are_append_only_and_allow_distinct_reads_with_the_same_idempotency_hash(): void
    {
        $idempotencyHash = str_repeat('a', 64);
        $firstEvent = $this->sensitiveAccessEvent('018f6f7d-0c00-7000-8000-000000000824', $idempotencyHash);
        $secondEvent = $this->sensitiveAccessEvent('018f6f7d-0c00-7000-8000-000000000825', $idempotencyHash);

        DB::table('sensitive_access_events')->insert([$firstEvent, $secondEvent]);

        $this->assertQueryRejected(fn () => DB::table('sensitive_access_events')
            ->where('id', $firstEvent['id'])
            ->update(['action' => 'export']));
        $this->assertQueryRejected(fn () => DB::table('sensitive_access_events')
            ->where('id', $firstEvent['id'])
            ->delete());

        $this->assertDatabaseCount('sensitive_access_events', 2);
    }

    /** @return array<string, string> */
    private function sensitiveAccessEvent(string $id, string $idempotencyHash): array
    {
        return [
            'id' => $id,
            'access_decision_id' => '018f6f7d-0c00-7000-8000-000000000826',
            'actor_user_id' => '018f6f7d-0c00-7000-8000-000000000813',
            'original_actor_user_id' => '018f6f7d-0c00-7000-8000-000000000813',
            'resource_type' => 'person',
            'resource_id' => '018f6f7d-0c00-7000-8000-000000000827',
            'action' => 'read',
            'classification_code' => 'confidential',
            'correlation_id' => '018f6f7d-0c00-7000-8000-000000000828',
            'idempotency_key_hash' => $idempotencyHash,
            'occurred_at' => '2026-07-20 10:00:00',
        ];
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the database trigger to reject the write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
