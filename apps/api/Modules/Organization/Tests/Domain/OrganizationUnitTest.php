<?php

declare(strict_types=1);

namespace Modules\Organization\Tests\Domain;

use InvalidArgumentException;
use Modules\Organization\Domain\OrganizationUnit;
use PHPUnit\Framework\TestCase;

final class OrganizationUnitTest extends TestCase
{
    private const VALID_ID = '018f6f7d-0c00-7000-8000-000000000020';

    private const CLUSTER_ID = '018f6f7d-0c00-7000-8000-000000000021';

    private const PARENT_ID = '018f6f7d-0c00-7000-8000-000000000022';

    public function test_create_returns_value_object_with_supplied_fields(): void
    {
        $unit = OrganizationUnit::create(
            self::VALID_ID,
            self::CLUSTER_ID,
            self::PARENT_ID,
            'cluster',
            'department',
            'DEP-01',
            'قسم الجراحة',
            'Surgery Department',
            '/cluster/department',
            2,
        );

        self::assertSame(self::VALID_ID, $unit->id);
        self::assertSame(self::CLUSTER_ID, $unit->clusterId);
        self::assertSame(self::PARENT_ID, $unit->parentId);
        self::assertSame('cluster', $unit->parentType);
        self::assertSame('department', $unit->typeCode);
        self::assertSame('DEP-01', $unit->code);
        self::assertSame('قسم الجراحة', $unit->nameAr);
        self::assertSame('Surgery Department', $unit->nameEn);
        self::assertSame('/cluster/department', $unit->pathCache);
        self::assertSame(2, $unit->depth);
    }

    public function test_create_accepts_each_allowed_parent_type(): void
    {
        foreach (['cluster', 'facility', 'unit'] as $parentType) {
            $unit = OrganizationUnit::create(
                self::VALID_ID,
                self::CLUSTER_ID,
                self::PARENT_ID,
                $parentType,
                'department',
                'DEP-01',
                'قسم',
                null,
                '/x',
                1,
            );

            self::assertSame($parentType, $unit->parentType);
        }
    }

    public function test_create_accepts_null_english_name(): void
    {
        $unit = OrganizationUnit::create(
            self::VALID_ID,
            self::CLUSTER_ID,
            self::PARENT_ID,
            'cluster',
            'department',
            'DEP-01',
            'قسم',
            null,
            '/x',
            1,
        );

        self::assertNull($unit->nameEn);
    }

    public function test_to_array_shape_matches_persistence_columns(): void
    {
        $unit = OrganizationUnit::create(
            self::VALID_ID,
            self::CLUSTER_ID,
            self::PARENT_ID,
            'facility',
            'department',
            'DEP-01',
            'قسم',
            'Surgery',
            '/facility/dep',
            2,
        );

        self::assertSame([
            'id' => self::VALID_ID,
            'cluster_id' => self::CLUSTER_ID,
            'parent_id' => self::PARENT_ID,
            'parent_type' => 'facility',
            'type_code' => 'department',
            'code' => 'DEP-01',
            'name_ar' => 'قسم',
            'name_en' => 'Surgery',
            'status' => 'active',
            'path_cache' => '/facility/dep',
            'depth' => 2,
            'lock_version' => 1,
        ], $unit->toArray());
    }

    public function test_rejects_id_that_is_not_a_lowercase_uuidv7(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Organization unit identifiers must be lowercase UUIDv7 values.');

        OrganizationUnit::create(
            'not-a-uuid',
            self::CLUSTER_ID,
            self::PARENT_ID,
            'cluster',
            'department',
            'DEP-01',
            'قسم',
            null,
            '/x',
            1,
        );
    }

    public function test_rejects_cluster_id_that_is_not_a_lowercase_uuidv7(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Organization unit identifiers must be lowercase UUIDv7 values.');

        OrganizationUnit::create(
            self::VALID_ID,
            'not-a-uuid',
            self::PARENT_ID,
            'cluster',
            'department',
            'DEP-01',
            'قسم',
            null,
            '/x',
            1,
        );
    }

    public function test_rejects_parent_id_that_is_not_a_lowercase_uuidv7(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Organization unit identifiers must be lowercase UUIDv7 values.');

        OrganizationUnit::create(
            self::VALID_ID,
            self::CLUSTER_ID,
            'not-a-uuid',
            'cluster',
            'department',
            'DEP-01',
            'قسم',
            null,
            '/x',
            1,
        );
    }

    public function test_rejects_unknown_parent_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Organization unit data is invalid.');

        OrganizationUnit::create(
            self::VALID_ID,
            self::CLUSTER_ID,
            self::PARENT_ID,
            'region',
            'department',
            'DEP-01',
            'قسم',
            null,
            '/x',
            1,
        );
    }

    public function test_rejects_type_code_starting_with_uppercase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Organization unit data is invalid.');

        OrganizationUnit::create(
            self::VALID_ID,
            self::CLUSTER_ID,
            self::PARENT_ID,
            'cluster',
            'Department',
            'DEP-01',
            'قسم',
            null,
            '/x',
            1,
        );
    }

    public function test_rejects_type_code_with_disallowed_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Organization unit data is invalid.');

        OrganizationUnit::create(
            self::VALID_ID,
            self::CLUSTER_ID,
            self::PARENT_ID,
            'cluster',
            'dept!',
            'DEP-01',
            'قسم',
            null,
            '/x',
            1,
        );
    }

    public function test_rejects_code_with_lowercase_letters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Organization unit data is invalid.');

        OrganizationUnit::create(
            self::VALID_ID,
            self::CLUSTER_ID,
            self::PARENT_ID,
            'cluster',
            'department',
            'dep-01',
            'قسم',
            null,
            '/x',
            1,
        );
    }

    public function test_rejects_empty_arabic_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Organization unit data is invalid.');

        OrganizationUnit::create(
            self::VALID_ID,
            self::CLUSTER_ID,
            self::PARENT_ID,
            'cluster',
            'department',
            'DEP-01',
            '',
            null,
            '/x',
            1,
        );
    }

    public function test_rejects_arabic_name_longer_than_255_multibyte_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Organization unit data is invalid.');

        OrganizationUnit::create(
            self::VALID_ID,
            self::CLUSTER_ID,
            self::PARENT_ID,
            'cluster',
            'department',
            'DEP-01',
            str_repeat('ع', 256),
            null,
            '/x',
            1,
        );
    }

    public function test_rejects_english_name_longer_than_255_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Organization unit data is invalid.');

        OrganizationUnit::create(
            self::VALID_ID,
            self::CLUSTER_ID,
            self::PARENT_ID,
            'cluster',
            'department',
            'DEP-01',
            'قسم',
            str_repeat('a', 256),
            '/x',
            1,
        );
    }

    public function test_rejects_depth_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Organization unit data is invalid.');

        OrganizationUnit::create(
            self::VALID_ID,
            self::CLUSTER_ID,
            self::PARENT_ID,
            'cluster',
            'department',
            'DEP-01',
            'قسم',
            null,
            '/x',
            0,
        );
    }

    public function test_rejects_path_cache_longer_than_512_bytes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Organization unit data is invalid.');

        OrganizationUnit::create(
            self::VALID_ID,
            self::CLUSTER_ID,
            self::PARENT_ID,
            'cluster',
            'department',
            'DEP-01',
            'قسم',
            null,
            str_repeat('a', 513),
            1,
        );
    }

    public function test_accepts_path_cache_at_512_bytes(): void
    {
        $cache = str_repeat('a', 512);

        $unit = OrganizationUnit::create(
            self::VALID_ID,
            self::CLUSTER_ID,
            self::PARENT_ID,
            'cluster',
            'department',
            'DEP-01',
            'قسم',
            null,
            $cache,
            1,
        );

        self::assertSame($cache, $unit->pathCache);
    }
}
