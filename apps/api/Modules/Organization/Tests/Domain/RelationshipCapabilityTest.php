<?php

declare(strict_types=1);

namespace Modules\Organization\Tests\Domain;

use InvalidArgumentException;
use Modules\Organization\Domain\RelationshipCapability;
use PHPUnit\Framework\TestCase;

final class RelationshipCapabilityTest extends TestCase
{
    private const VALID_ID = '018f6f7d-0c00-7000-8000-000000000070';

    private const RELATIONSHIP_ID = '018f6f7d-0c00-7000-8000-000000000071';

    public function test_create_returns_value_object_with_supplied_fields(): void
    {
        $cap = RelationshipCapability::create(
            self::VALID_ID,
            self::RELATIONSHIP_ID,
            'work-records',
            'view_details',
        );

        self::assertSame(self::VALID_ID, $cap->id);
        self::assertSame(self::RELATIONSHIP_ID, $cap->supervisoryRelationshipId);
        self::assertSame('work-records', $cap->moduleCode);
        self::assertSame('view_details', $cap->capabilityCode);
    }

    public function test_to_fact_shape_omits_supervisory_relationship_id(): void
    {
        $cap = RelationshipCapability::create(
            self::VALID_ID,
            self::RELATIONSHIP_ID,
            'work-records',
            'view_details',
        );

        self::assertSame([
            'relationship_capability_id' => self::VALID_ID,
            'module_code' => 'work-records',
            'capability_code' => 'view_details',
        ], $cap->toFact());
    }

    public function test_to_persistence_shape_includes_all_columns(): void
    {
        $cap = RelationshipCapability::create(
            self::VALID_ID,
            self::RELATIONSHIP_ID,
            'work-records',
            'view_details',
        );

        self::assertSame([
            'id' => self::VALID_ID,
            'supervisory_relationship_id' => self::RELATIONSHIP_ID,
            'module_code' => 'work-records',
            'capability_code' => 'view_details',
        ], $cap->toPersistence());
    }

    public function test_rejects_id_that_is_not_a_lowercase_uuidv7(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship_capability_identifiers_invalid');

        RelationshipCapability::create('not-a-uuid', self::RELATIONSHIP_ID, 'work-records', 'view_details');
    }

    public function test_rejects_supervisory_relationship_id_that_is_not_a_lowercase_uuidv7(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship_capability_identifiers_invalid');

        RelationshipCapability::create(self::VALID_ID, 'not-a-uuid', 'work-records', 'view_details');
    }

    public function test_rejects_id_with_uppercase_letters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship_capability_identifiers_invalid');

        RelationshipCapability::create(
            '018F6F7D-0C00-7000-8000-000000000070',
            self::RELATIONSHIP_ID,
            'work-records',
            'view_details',
        );
    }

    public function test_rejects_module_code_starting_with_uppercase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship_capability_code_invalid');

        RelationshipCapability::create(
            self::VALID_ID,
            self::RELATIONSHIP_ID,
            'Work-records',
            'view_details',
        );
    }

    public function test_rejects_module_code_with_dot_character(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship_capability_code_invalid');

        RelationshipCapability::create(
            self::VALID_ID,
            self::RELATIONSHIP_ID,
            'work.records',
            'view_details',
        );
    }

    public function test_rejects_module_code_with_space(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship_capability_code_invalid');

        RelationshipCapability::create(
            self::VALID_ID,
            self::RELATIONSHIP_ID,
            'work records',
            'view_details',
        );
    }

    public function test_rejects_module_code_starting_with_digit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship_capability_code_invalid');

        RelationshipCapability::create(
            self::VALID_ID,
            self::RELATIONSHIP_ID,
            '1work',
            'view_details',
        );
    }

    public function test_rejects_capability_code_starting_with_uppercase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship_capability_code_invalid');

        RelationshipCapability::create(
            self::VALID_ID,
            self::RELATIONSHIP_ID,
            'work-records',
            'View_details',
        );
    }

    public function test_rejects_capability_code_with_space(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship_capability_code_invalid');

        RelationshipCapability::create(
            self::VALID_ID,
            self::RELATIONSHIP_ID,
            'work-records',
            'view details',
        );
    }

    public function test_rejects_capability_code_with_glob_wildcard(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship_capability_code_invalid');

        RelationshipCapability::create(
            self::VALID_ID,
            self::RELATIONSHIP_ID,
            'work-records',
            'view*',
        );
    }

    public function test_rejects_capability_code_that_is_just_a_wildcard(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship_capability_code_invalid');

        RelationshipCapability::create(
            self::VALID_ID,
            self::RELATIONSHIP_ID,
            'work-records',
            '*',
        );
    }

    public function test_rejects_capability_code_with_question_mark_wildcard(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship_capability_code_invalid');

        RelationshipCapability::create(
            self::VALID_ID,
            self::RELATIONSHIP_ID,
            'work-records',
            'view?details',
        );
    }

    public function test_rejects_capability_code_starting_with_digit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship_capability_code_invalid');

        RelationshipCapability::create(
            self::VALID_ID,
            self::RELATIONSHIP_ID,
            'work-records',
            '1view',
        );
    }

    public function test_accepts_capability_code_with_dot_path_separator(): void
    {
        $cap = RelationshipCapability::create(
            self::VALID_ID,
            self::RELATIONSHIP_ID,
            'work-records',
            'module.action',
        );

        self::assertSame('module.action', $cap->capabilityCode);
    }

    public function test_accepts_capability_code_at_minimum_length_one_char(): void
    {
        $cap = RelationshipCapability::create(
            self::VALID_ID,
            self::RELATIONSHIP_ID,
            'work-records',
            'v',
        );

        self::assertSame('v', $cap->capabilityCode);
    }

    public function test_accepts_capability_code_at_maximum_length_64_chars(): void
    {
        $code = 'a'.str_repeat('b', 63);

        $cap = RelationshipCapability::create(
            self::VALID_ID,
            self::RELATIONSHIP_ID,
            'work-records',
            $code,
        );

        self::assertSame($code, $cap->capabilityCode);
    }

    public function test_rejects_capability_code_longer_than_64_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship_capability_code_invalid');

        RelationshipCapability::create(
            self::VALID_ID,
            self::RELATIONSHIP_ID,
            'work-records',
            'a'.str_repeat('b', 64),
        );
    }

    public function test_rejects_module_code_longer_than_64_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship_capability_code_invalid');

        RelationshipCapability::create(
            self::VALID_ID,
            self::RELATIONSHIP_ID,
            'a'.str_repeat('b', 64),
            'view_details',
        );
    }
}
