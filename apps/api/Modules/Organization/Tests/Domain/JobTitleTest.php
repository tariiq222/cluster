<?php

declare(strict_types=1);

namespace Modules\Organization\Tests\Domain;

use InvalidArgumentException;
use Modules\Organization\Domain\JobTitle;
use PHPUnit\Framework\TestCase;

final class JobTitleTest extends TestCase
{
    private const VALID_ID = '018f6f7d-0c00-7000-8000-000000000050';

    public function test_create_returns_value_object_with_supplied_fields(): void
    {
        $title = JobTitle::create(self::VALID_ID, 'JOB-001', 'مهندس برمجيات');

        self::assertSame(self::VALID_ID, $title->id);
        self::assertSame('JOB-001', $title->code);
        self::assertSame('مهندس برمجيات', $title->titleAr);
    }

    public function test_to_array_shape_matches_persistence_columns(): void
    {
        $title = JobTitle::create(self::VALID_ID, 'JOB-001', 'مهندس برمجيات');

        self::assertSame([
            'id' => self::VALID_ID,
            'code' => 'JOB-001',
            'title_ar' => 'مهندس برمجيات',
            'status' => 'active',
        ], $title->toArray());
    }

    public function test_rejects_id_that_is_not_a_lowercase_uuidv7(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JobTitle id must be a lowercase UUIDv7.');

        JobTitle::create('not-a-uuid', 'JOB-001', 'مهندس');
    }

    public function test_rejects_id_with_uppercase_letters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JobTitle id must be a lowercase UUIDv7.');

        JobTitle::create('018F6F7D-0C00-7000-8000-000000000050', 'JOB-001', 'مهندس');
    }

    public function test_rejects_code_with_lowercase_letters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JobTitle code must match [A-Z0-9_-]{2,96}.');

        JobTitle::create(self::VALID_ID, 'job-001', 'مهندس');
    }

    public function test_rejects_code_shorter_than_two_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JobTitle code must match [A-Z0-9_-]{2,96}.');

        JobTitle::create(self::VALID_ID, 'J', 'مهندس');
    }

    public function test_rejects_code_longer_than_96_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JobTitle code must match [A-Z0-9_-]{2,96}.');

        JobTitle::create(self::VALID_ID, str_repeat('A', 97), 'مهندس');
    }

    public function test_accepts_code_at_minimum_length(): void
    {
        $title = JobTitle::create(self::VALID_ID, 'JO', 'مهندس');

        self::assertSame('JO', $title->code);
    }

    public function test_accepts_code_at_maximum_length_96(): void
    {
        $code = str_repeat('A', 96);

        $title = JobTitle::create(self::VALID_ID, $code, 'مهندس');

        self::assertSame($code, $title->code);
    }

    public function test_rejects_code_with_disallowed_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JobTitle code must match [A-Z0-9_-]{2,96}.');

        JobTitle::create(self::VALID_ID, 'JOB 001', 'مهندس');
    }

    public function test_rejects_empty_arabic_title(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JobTitle title_ar is invalid.');

        JobTitle::create(self::VALID_ID, 'JOB-001', '');
    }

    public function test_rejects_arabic_title_longer_than_255_multibyte_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JobTitle title_ar is invalid.');

        JobTitle::create(self::VALID_ID, 'JOB-001', str_repeat('ع', 256));
    }

    public function test_accepts_arabic_title_at_255_multibyte_chars(): void
    {
        $text = str_repeat('ع', 255);

        $title = JobTitle::create(self::VALID_ID, 'JOB-001', $text);

        self::assertSame($text, $title->titleAr);
    }
}
