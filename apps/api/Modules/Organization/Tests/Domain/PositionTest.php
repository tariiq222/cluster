<?php

declare(strict_types=1);

namespace Modules\Organization\Tests\Domain;

use InvalidArgumentException;
use Modules\Organization\Domain\Position;
use PHPUnit\Framework\TestCase;

final class PositionTest extends TestCase
{
    private const VALID_ID = '018f6f7d-0c00-7000-8000-000000000040';

    private const UNIT_ID = '018f6f7d-0c00-7000-8000-000000000041';

    private const MANAGER_ID = '018f6f7d-0c00-7000-8000-000000000042';

    public function test_create_returns_value_object_with_supplied_fields(): void
    {
        $position = Position::create(
            self::VALID_ID,
            self::UNIT_ID,
            'POS-01',
            'مدير القسم',
            self::MANAGER_ID,
        );

        self::assertSame(self::VALID_ID, $position->id);
        self::assertSame(self::UNIT_ID, $position->organizationUnitId);
        self::assertSame('POS-01', $position->code);
        self::assertSame('مدير القسم', $position->titleAr);
        self::assertSame(self::MANAGER_ID, $position->managerPositionId);
    }

    public function test_create_accepts_null_manager_position_id(): void
    {
        $position = Position::create(self::VALID_ID, self::UNIT_ID, 'POS-01', 'مدير القسم', null);

        self::assertNull($position->managerPositionId);
    }

    public function test_to_array_shape_matches_persistence_columns(): void
    {
        $position = Position::create(
            self::VALID_ID,
            self::UNIT_ID,
            'POS-01',
            'مدير القسم',
            self::MANAGER_ID,
        );

        self::assertSame([
            'id' => self::VALID_ID,
            'organization_unit_id' => self::UNIT_ID,
            'code' => 'POS-01',
            'title_ar' => 'مدير القسم',
            'manager_position_id' => self::MANAGER_ID,
            'is_active' => true,
            'lock_version' => 1,
        ], $position->toArray());
    }

    public function test_rejects_id_that_is_not_a_lowercase_uuidv7(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Position identifiers must be lowercase UUIDv7 values.');

        Position::create('not-a-uuid', self::UNIT_ID, 'POS-01', 'مدير', null);
    }

    public function test_rejects_organization_unit_id_that_is_not_a_lowercase_uuidv7(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Position identifiers must be lowercase UUIDv7 values.');

        Position::create(self::VALID_ID, 'not-a-uuid', 'POS-01', 'مدير', null);
    }

    public function test_rejects_manager_position_id_that_is_not_a_lowercase_uuidv7(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Position identifiers must be lowercase UUIDv7 values.');

        Position::create(self::VALID_ID, self::UNIT_ID, 'POS-01', 'مدير', 'not-a-uuid');
    }

    public function test_skips_uuid_check_when_manager_position_id_is_null(): void
    {
        $position = Position::create(self::VALID_ID, self::UNIT_ID, 'POS-01', 'مدير', null);

        self::assertNull($position->managerPositionId);
    }

    public function test_rejects_code_with_lowercase_letters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Position data is invalid.');

        Position::create(self::VALID_ID, self::UNIT_ID, 'pos-01', 'مدير', null);
    }

    public function test_rejects_code_shorter_than_two_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Position data is invalid.');

        Position::create(self::VALID_ID, self::UNIT_ID, 'P', 'مدير', null);
    }

    public function test_rejects_code_longer_than_64_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Position data is invalid.');

        Position::create(self::VALID_ID, self::UNIT_ID, str_repeat('A', 65), 'مدير', null);
    }

    public function test_accepts_code_at_minimum_length(): void
    {
        $position = Position::create(self::VALID_ID, self::UNIT_ID, 'PO', 'مدير', null);

        self::assertSame('PO', $position->code);
    }

    public function test_accepts_code_at_maximum_length(): void
    {
        $code = str_repeat('A', 64);

        $position = Position::create(self::VALID_ID, self::UNIT_ID, $code, 'مدير', null);

        self::assertSame($code, $position->code);
    }

    public function test_rejects_empty_arabic_title(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Position data is invalid.');

        Position::create(self::VALID_ID, self::UNIT_ID, 'POS-01', '', null);
    }

    public function test_rejects_arabic_title_longer_than_255_multibyte_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Position data is invalid.');

        Position::create(self::VALID_ID, self::UNIT_ID, 'POS-01', str_repeat('ع', 256), null);
    }
}
