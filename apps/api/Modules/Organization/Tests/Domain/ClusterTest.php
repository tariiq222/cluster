<?php

declare(strict_types=1);

namespace Modules\Organization\Tests\Domain;

use InvalidArgumentException;
use Modules\Organization\Domain\Cluster;
use PHPUnit\Framework\TestCase;

final class ClusterTest extends TestCase
{
    private const VALID_ID = '018f6f7d-0c00-7000-8000-000000000001';

    public function test_create_returns_value_object_with_supplied_fields(): void
    {
        $cluster = Cluster::create(self::VALID_ID, 'HQ-01', 'التجمع الرئيسي', 'Headquarters');

        self::assertSame(self::VALID_ID, $cluster->id);
        self::assertSame('HQ-01', $cluster->code);
        self::assertSame('التجمع الرئيسي', $cluster->nameAr);
        self::assertSame('Headquarters', $cluster->nameEn);
    }

    public function test_create_accepts_optional_english_name_as_null(): void
    {
        $cluster = Cluster::create(self::VALID_ID, 'HQ-01', 'التجمع الرئيسي', null);

        self::assertNull($cluster->nameEn);
    }

    public function test_to_array_shape_matches_persistence_columns(): void
    {
        $cluster = Cluster::create(self::VALID_ID, 'HQ-01', 'التجمع الرئيسي', 'Headquarters');

        self::assertSame([
            'id' => self::VALID_ID,
            'code' => 'HQ-01',
            'name_ar' => 'التجمع الرئيسي',
            'name_en' => 'Headquarters',
            'status' => 'active',
            'lock_version' => 1,
        ], $cluster->toArray());
    }

    public function test_rejects_id_that_is_not_a_lowercase_uuidv7(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cluster id must be a lowercase UUIDv7.');

        Cluster::create('018F6F7D-0C00-7000-8000-000000000001', 'HQ-01', 'التجمع الرئيسي', null);
    }

    public function test_rejects_id_with_wrong_uuid_version(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cluster id must be a lowercase UUIDv7.');

        // Version 4, not v7.
        Cluster::create('018f6f7d-0c00-4000-8000-000000000001', 'HQ-01', 'التجمع الرئيسي', null);
    }

    public function test_rejects_id_with_uppercase_letters_anywhere(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cluster id must be a lowercase UUIDv7.');

        Cluster::create('018f6f7d-0c00-7000-8000-00000000000A', 'HQ-01', 'التجمع الرئيسي', null);
    }

    public function test_rejects_code_that_is_too_short(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cluster code is invalid.');

        Cluster::create(self::VALID_ID, 'A', 'التجمع الرئيسي', null);
    }

    public function test_rejects_code_that_is_too_long(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cluster code is invalid.');

        Cluster::create(self::VALID_ID, str_repeat('A', 65), 'التجمع الرئيسي', null);
    }

    public function test_rejects_code_with_lowercase_letters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cluster code is invalid.');

        Cluster::create(self::VALID_ID, 'hq-01', 'التجمع الرئيسي', null);
    }

    public function test_rejects_code_with_disallowed_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cluster code is invalid.');

        Cluster::create(self::VALID_ID, 'HQ 01', 'التجمع الرئيسي', null);
    }

    public function test_accepts_code_at_minimum_length(): void
    {
        $cluster = Cluster::create(self::VALID_ID, 'HQ', 'التجمع الرئيسي', null);

        self::assertSame('HQ', $cluster->code);
    }

    public function test_accepts_code_at_maximum_length(): void
    {
        $code = str_repeat('A', 64);

        $cluster = Cluster::create(self::VALID_ID, $code, 'التجمع الرئيسي', null);

        self::assertSame($code, $cluster->code);
    }

    public function test_rejects_empty_arabic_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cluster name is invalid.');

        Cluster::create(self::VALID_ID, 'HQ-01', '', null);
    }

    public function test_rejects_arabic_name_longer_than_255_multibyte_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cluster name is invalid.');

        // 256 Arabic characters — each is one mb char.
        Cluster::create(self::VALID_ID, 'HQ-01', str_repeat('ع', 256), null);
    }

    public function test_accepts_arabic_name_at_255_multibyte_chars(): void
    {
        $name = str_repeat('ع', 255);

        $cluster = Cluster::create(self::VALID_ID, 'HQ-01', $name, null);

        self::assertSame($name, $cluster->nameAr);
    }

    public function test_rejects_english_name_longer_than_255_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cluster name is invalid.');

        Cluster::create(self::VALID_ID, 'HQ-01', 'التجمع الرئيسي', str_repeat('a', 256));
    }
}
