<?php

namespace Modules\Organization\Tests;

use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Modules\Organization\Contracts\GetActiveSupervisoryRelationships;
use Modules\Organization\Domain\RelationshipCapability;
use Modules\Organization\Domain\SupervisoryRelationship;
use ReflectionClass;
use Tests\TestCase;

class SupervisoryRelationshipTest extends TestCase
{
    use RefreshDatabase;

    private const CLUSTER_ID = '018f6f7d-0c00-7000-8000-000000000801';

    private const SOURCE_UNIT_ID = '018f6f7d-0c00-7000-8000-000000000802';

    private const TARGET_UNIT_ID = '018f6f7d-0c00-7000-8000-000000000803';

    private const UNIT_TYPE_ID = '018f6f7d-0c00-7000-8000-000000000804';

    private const RELATIONSHIP_ID = '018f6f7d-0c00-7000-8000-000000000805';

    private const CAPABILITY_ID = '018f6f7d-0c00-7000-8000-000000000806';

    protected function beforeRefreshingDatabase(): void
    {
        if (Schema::hasTable('organization_units') && ! Schema::hasTable('supervisory_relationships')) {
            $this->migrateSupervisoryRelationships();
        }
    }

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('clusters')) {
            $this->migrateOrganizationReferences();
        }
        if (! Schema::hasTable('supervisory_relationships')) {
            $this->migrateSupervisoryRelationships();
        }
        $this->seedOrganizationReferences();
    }

    public function test_relationship_facts_normalize_the_utc_period_and_expire_without_stored_state(): void
    {
        $capability = RelationshipCapability::create(
            self::CAPABILITY_ID,
            self::RELATIONSHIP_ID,
            'work-records',
            'view_details',
        );
        $relationship = SupervisoryRelationship::create(
            self::RELATIONSHIP_ID,
            self::SOURCE_UNIT_ID,
            self::TARGET_UNIT_ID,
            'functional',
            new DateTimeImmutable('2026-07-18T10:00:00+03:00'),
            new DateTimeImmutable('2026-07-19T10:00:00+03:00'),
            [$capability],
        );

        $this->assertSame('2026-07-18T07:00:00.000Z', $relationship->toFact()['valid_from']);
        $this->assertSame('2026-07-19T07:00:00.000Z', $relationship->toFact()['valid_until']);
        $this->assertTrue($relationship->isActiveAt(new DateTimeImmutable('2026-07-18T07:00:00Z')));
        $this->assertTrue($relationship->isActiveAt(new DateTimeImmutable('2026-07-19T06:59:59.999Z')));
        $this->assertFalse($relationship->isActiveAt(new DateTimeImmutable('2026-07-19T07:00:00Z')));
        $this->assertNull($relationship->activeFactAt(new DateTimeImmutable('2026-07-19T07:00:00Z')));
        $this->assertSame([
            [
                'relationship_capability_id' => self::CAPABILITY_ID,
                'module_code' => 'work-records',
                'capability_code' => 'view_details',
            ],
        ], $relationship->toFact()['relationship_capabilities']);
        $this->assertArrayNotHasKey('state', $relationship->toPersistence());
    }

    public function test_relationship_and_capability_reject_invalid_identifiers_types_periods_and_codes(): void
    {
        $cases = [
            'supervisory_relationship_identifiers_invalid' => fn (): SupervisoryRelationship => SupervisoryRelationship::create(
                '018F6F7D-0C00-7000-8000-000000000805',
                self::SOURCE_UNIT_ID,
                self::TARGET_UNIT_ID,
                'direct',
                new DateTimeImmutable('2026-07-18T10:00:00Z'),
                new DateTimeImmutable('2026-07-19T10:00:00Z'),
            ),
            'supervisory_relationship_type_invalid' => fn (): SupervisoryRelationship => SupervisoryRelationship::create(
                self::RELATIONSHIP_ID,
                self::SOURCE_UNIT_ID,
                self::TARGET_UNIT_ID,
                'none',
                new DateTimeImmutable('2026-07-18T10:00:00Z'),
                new DateTimeImmutable('2026-07-19T10:00:00Z'),
            ),
            'supervisory_relationship_period_invalid' => fn (): SupervisoryRelationship => SupervisoryRelationship::create(
                self::RELATIONSHIP_ID,
                self::SOURCE_UNIT_ID,
                self::TARGET_UNIT_ID,
                'direct',
                new DateTimeImmutable('2026-07-19T10:00:00Z'),
                new DateTimeImmutable('2026-07-19T10:00:00Z'),
            ),
            'relationship_capability_identifiers_invalid' => fn (): RelationshipCapability => RelationshipCapability::create(
                self::CAPABILITY_ID,
                '018F6F7D-0C00-7000-8000-000000000805',
                'work-records',
                'view_details',
            ),
            'relationship_capability_code_invalid' => fn (): RelationshipCapability => RelationshipCapability::create(
                self::CAPABILITY_ID,
                self::RELATIONSHIP_ID,
                'work records',
                'view_details',
            ),
        ];

        foreach ($cases as $message => $callback) {
            $this->assertInvalid($callback, $message);
        }
    }

    public function test_fact_contract_declares_only_active_relationship_facts(): void
    {
        $contract = new ReflectionClass(GetActiveSupervisoryRelationships::class);

        $this->assertTrue($contract->isInterface());
        $this->assertSame(['forSourceOrganizationUnit'], array_map(
            static fn ($method): string => $method->getName(),
            $contract->getMethods(),
        ));
        $this->assertSame('array', (string) $contract->getMethod('forSourceOrganizationUnit')->getReturnType());
    }

    public function test_persistence_enforces_relationship_period_type_and_capability_identity(): void
    {
        $relationship = SupervisoryRelationship::create(
            self::RELATIONSHIP_ID,
            self::SOURCE_UNIT_ID,
            self::TARGET_UNIT_ID,
            'coordination',
            new DateTimeImmutable('2026-07-18T10:00:00Z'),
            new DateTimeImmutable('2026-07-19T10:00:00Z'),
        );
        DB::table('supervisory_relationships')->insert([
            ...$relationship->toPersistence(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $capability = RelationshipCapability::create(
            self::CAPABILITY_ID,
            self::RELATIONSHIP_ID,
            'work-records',
            'view_details',
        );
        DB::table('relationship_capabilities')->insert($capability->toPersistence());

        $this->assertDatabaseHas('supervisory_relationships', [
            'id' => self::RELATIONSHIP_ID,
            'relationship_type' => 'coordination',
        ]);
        $this->assertDatabaseHas('relationship_capabilities', [
            'id' => self::CAPABILITY_ID,
            'module_code' => 'work-records',
            'capability_code' => 'view_details',
        ]);
        $this->assertQueryRejected(fn (): mixed => DB::table('supervisory_relationships')->insert([
            ...$relationship->toPersistence(),
            'id' => '018f6f7d-0c00-7000-8000-000000000807',
            'relationship_type' => 'none',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $this->assertQueryRejected(fn (): mixed => DB::table('supervisory_relationships')->insert([
            ...$relationship->toPersistence(),
            'id' => '018f6f7d-0c00-7000-8000-000000000808',
            'valid_until' => $relationship->toPersistence()['valid_from'],
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $this->assertQueryRejected(fn (): mixed => DB::table('relationship_capabilities')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000809',
            'supervisory_relationship_id' => self::RELATIONSHIP_ID,
            'module_code' => 'work-records',
            'capability_code' => 'view_details',
        ]));
    }

    private function assertInvalid(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail("Expected InvalidArgumentException: {$message}");
        } catch (InvalidArgumentException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the database constraint to reject the write.');
        } catch (QueryException) {
            // The database invariant is the assertion target.
        }
    }

    private function migrateSupervisoryRelationships(): void
    {
        $migration = require dirname(__DIR__).'/Infrastructure/Persistence/Migrations/CreateOrganizationSupervisoryRelationshipTables.php';
        $migration->up();
    }

    private function migrateOrganizationReferences(): void
    {
        $core = require dirname(__DIR__).'/Infrastructure/Persistence/Migrations/CreateOrganizationCoreTables.php';
        $core->up();
        $tree = require dirname(__DIR__).'/Infrastructure/Persistence/Migrations/CreateOrganizationTreeTables.php';
        $tree->up();
    }

    private function seedOrganizationReferences(): void
    {
        DB::table('clusters')->insert([
            'id' => self::CLUSTER_ID,
            'code' => 'REL',
            'name_ar' => 'تجمع الاختبار',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('unit_types')->insert([
            'id' => self::UNIT_TYPE_ID,
            'code' => 'relationship_test_unit',
            'name_ar' => 'وحدة اختبار',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([self::SOURCE_UNIT_ID => 'REL-SOURCE', self::TARGET_UNIT_ID => 'REL-TARGET'] as $id => $code) {
            DB::table('organization_units')->insert([
                'id' => $id,
                'cluster_id' => self::CLUSTER_ID,
                'parent_id' => self::CLUSTER_ID,
                'parent_type' => 'cluster',
                'unit_type_id' => self::UNIT_TYPE_ID,
                'code' => $code,
                'name_ar' => 'الوحدة المستهدفة',
                'status' => 'active',
                'path_cache' => '/'.$id,
                'depth' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
