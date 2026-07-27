<?php

declare(strict_types=1);

namespace Modules\Organization\Tests\Domain;

use InvalidArgumentException;
use Modules\Organization\Domain\Facility;
use PHPUnit\Framework\TestCase;

final class FacilityTest extends TestCase
{
    private const VALID_ID = '018f6f7d-0c00-7000-8000-000000000010';

    private const VALID_CLUSTER_ID = '018f6f7d-0c00-7000-8000-000000000011';

    public function test_create_returns_value_object_with_supplied_fields(): void
    {
        $facility = Facility::create(
            self::VALID_ID,
            self::VALID_CLUSTER_ID,
            'hospital',
            'FAC-01',
            'مستشفى الملك فهد',
            'King Fahd Hospital',
        );

        self::assertSame(self::VALID_ID, $facility->id);
        self::assertSame(self::VALID_CLUSTER_ID, $facility->clusterId);
        self::assertSame('hospital', $facility->typeCode);
        self::assertSame('FAC-01', $facility->code);
        self::assertSame('مستشفى الملك فهد', $facility->nameAr);
        self::assertSame('King Fahd Hospital', $facility->nameEn);
    }

    public function test_create_accepts_optional_english_name_as_null(): void
    {
        $facility = Facility::create(
            self::VALID_ID,
            self::VALID_CLUSTER_ID,
            'hospital',
            'FAC-01',
            'مستشفى الملك فهد',
            null,
        );

        self::assertNull($facility->nameEn);
    }

    public function test_to_array_shape_matches_persistence_columns(): void
    {
        $facility = Facility::create(
            self::VALID_ID,
            self::VALID_CLUSTER_ID,
            'hospital',
            'FAC-01',
            'مستشفى الملك فهد',
            'King Fahd Hospital',
        );

        self::assertSame([
            'id' => self::VALID_ID,
            'cluster_id' => self::VALID_CLUSTER_ID,
            'type_code' => 'hospital',
            'code' => 'FAC-01',
            'name_ar' => 'مستشفى الملك فهد',
            'name_en' => 'King Fahd Hospital',
            'status' => 'active',
            'lock_version' => 1,
        ], $facility->toArray());
    }

    public function test_rejects_facility_id_that_is_not_a_lowercase_uuidv7(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Facility id must be a lowercase UUIDv7.');

        Facility::create('not-a-uuid', self::VALID_CLUSTER_ID, 'hospital', 'FAC-01', 'اسم', null);
    }

    public function test_rejects_cluster_id_that_is_not_a_lowercase_uuidv7(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cluster id must be a lowercase UUIDv7.');

        Facility::create(self::VALID_ID, 'not-a-uuid', 'hospital', 'FAC-01', 'اسم', null);
    }

    public function test_rejects_type_code_starting_with_uppercase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Facility type or code is invalid.');

        Facility::create(self::VALID_ID, self::VALID_CLUSTER_ID, 'Hospital', 'FAC-01', 'اسم', null);
    }

    public function test_rejects_type_code_starting_with_digit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Facility type or code is invalid.');

        Facility::create(self::VALID_ID, self::VALID_CLUSTER_ID, '1hospital', 'FAC-01', 'اسم', null);
    }

    public function test_rejects_type_code_with_disallowed_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Facility type or code is invalid.');

        Facility::create(self::VALID_ID, self::VALID_CLUSTER_ID, 'hospital!', 'FAC-01', 'اسم', null);
    }

    public function test_rejects_type_code_shorter_than_two_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Facility type or code is invalid.');

        Facility::create(self::VALID_ID, self::VALID_CLUSTER_ID, 'h', 'FAC-01', 'اسم', null);
    }

    public function test_rejects_code_with_lowercase_letters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Facility type or code is invalid.');

        Facility::create(self::VALID_ID, self::VALID_CLUSTER_ID, 'hospital', 'fac-01', 'اسم', null);
    }

    public function test_rejects_code_shorter_than_two_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Facility type or code is invalid.');

        Facility::create(self::VALID_ID, self::VALID_CLUSTER_ID, 'hospital', 'F', 'اسم', null);
    }

    public function test_accepts_type_code_at_minimum_length_two_chars(): void
    {
        $facility = Facility::create(self::VALID_ID, self::VALID_CLUSTER_ID, 'ho', 'FAC-01', 'اسم', null);

        self::assertSame('ho', $facility->typeCode);
    }

    public function test_accepts_type_code_with_underscores_and_digits(): void
    {
        $facility = Facility::create(self::VALID_ID, self::VALID_CLUSTER_ID, 'h2_unit', 'FAC-01', 'اسم', null);

        self::assertSame('h2_unit', $facility->typeCode);
    }

    public function test_rejects_empty_arabic_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Facility name is invalid.');

        Facility::create(self::VALID_ID, self::VALID_CLUSTER_ID, 'hospital', 'FAC-01', '', null);
    }

    public function test_rejects_arabic_name_longer_than_255_multibyte_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Facility name is invalid.');

        Facility::create(self::VALID_ID, self::VALID_CLUSTER_ID, 'hospital', 'FAC-01', str_repeat('ع', 256), null);
    }

    public function test_rejects_english_name_longer_than_255_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Facility name is invalid.');

        Facility::create(self::VALID_ID, self::VALID_CLUSTER_ID, 'hospital', 'FAC-01', 'اسم', str_repeat('a', 256));
    }
}
