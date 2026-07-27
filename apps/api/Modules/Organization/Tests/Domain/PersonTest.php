<?php

declare(strict_types=1);

namespace Modules\Organization\Tests\Domain;

use InvalidArgumentException;
use Modules\Organization\Domain\Person;
use PHPUnit\Framework\TestCase;

final class PersonTest extends TestCase
{
    private const VALID_ID = '018f6f7d-0c00-7000-8000-000000000030';

    public function test_register_returns_value_object_with_supplied_fields(): void
    {
        $person = Person::register(self::VALID_ID, 'EMP-001', 'أحمد محمد', 'Ahmed Mohamed', 'active');

        self::assertSame(self::VALID_ID, $person->id);
        self::assertSame('EMP-001', $person->employeeNumber);
        self::assertSame('أحمد محمد', $person->displayNameAr);
        self::assertSame('Ahmed Mohamed', $person->displayNameEn);
        self::assertSame('active', $person->status);
    }

    public function test_register_accepts_null_english_name(): void
    {
        $person = Person::register(self::VALID_ID, 'EMP-001', 'أحمد محمد', null, 'active');

        self::assertNull($person->displayNameEn);
    }

    public function test_register_accepts_each_allowed_status(): void
    {
        foreach (['active', 'suspended', 'left'] as $status) {
            $person = Person::register(self::VALID_ID, 'EMP-001', 'أحمد', null, $status);

            self::assertSame($status, $person->status);
        }
    }

    public function test_to_array_shape_matches_persistence_columns(): void
    {
        $person = Person::register(self::VALID_ID, 'EMP-001', 'أحمد محمد', 'Ahmed', 'active');

        self::assertSame([
            'id' => self::VALID_ID,
            'employee_number' => 'EMP-001',
            'display_name_ar' => 'أحمد محمد',
            'display_name_en' => 'Ahmed',
            'status' => 'active',
            'person_version' => 1,
        ], $person->toArray());
    }

    public function test_rejects_id_that_is_not_a_lowercase_uuidv7(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Person identifier must be a lowercase UUIDv7 value.');

        Person::register('not-a-uuid', 'EMP-001', 'أحمد', null, 'active');
    }

    public function test_rejects_id_with_uppercase_letters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Person identifier must be a lowercase UUIDv7 value.');

        Person::register('018F6F7D-0C00-7000-8000-000000000030', 'EMP-001', 'أحمد', null, 'active');
    }

    public function test_rejects_empty_employee_number(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Person data is invalid.');

        Person::register(self::VALID_ID, '', 'أحمد', null, 'active');
    }

    public function test_rejects_employee_number_longer_than_64_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Person data is invalid.');

        Person::register(self::VALID_ID, str_repeat('A', 65), 'أحمد', null, 'active');
    }

    public function test_accepts_employee_number_at_64_chars(): void
    {
        $number = str_repeat('A', 64);

        $person = Person::register(self::VALID_ID, $number, 'أحمد', null, 'active');

        self::assertSame($number, $person->employeeNumber);
    }

    public function test_rejects_empty_arabic_display_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Person data is invalid.');

        Person::register(self::VALID_ID, 'EMP-001', '', null, 'active');
    }

    public function test_rejects_arabic_display_name_longer_than_255_multibyte_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Person data is invalid.');

        Person::register(self::VALID_ID, 'EMP-001', str_repeat('ع', 256), null, 'active');
    }

    public function test_rejects_english_display_name_longer_than_255_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Person data is invalid.');

        Person::register(self::VALID_ID, 'EMP-001', 'أحمد', str_repeat('a', 256), 'active');
    }

    public function test_rejects_unknown_status(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Person data is invalid.');

        Person::register(self::VALID_ID, 'EMP-001', 'أحمد', null, 'terminated');
    }

    public function test_rejects_empty_status(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Person data is invalid.');

        Person::register(self::VALID_ID, 'EMP-001', 'أحمد', null, '');
    }
}
