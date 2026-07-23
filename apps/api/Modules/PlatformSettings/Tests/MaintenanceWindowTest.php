<?php

namespace Modules\PlatformSettings\Tests;

use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\PlatformSettings\Domain\MaintenanceWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MaintenanceWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_window_rejects_an_end_before_its_start(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MaintenanceWindow(
            id: '019f8e3b-3368-7192-85a6-3da3949fd751',
            startsAt: new DateTimeImmutable('2026-07-23T10:00:00+03:00'),
            endsAt: new DateTimeImmutable('2026-07-23T09:59:00+03:00'),
            messageAr: 'صيانة مجدولة',
            messageEn: 'Scheduled maintenance',
        );
    }

    #[DataProvider('blankMessages')]
    public function test_window_rejects_a_blank_localized_message(string $messageAr, string $messageEn): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MaintenanceWindow(
            id: '019f8e3b-3368-7192-85a6-3da3949fd751',
            startsAt: new DateTimeImmutable('2026-07-23T10:00:00+03:00'),
            endsAt: null,
            messageAr: $messageAr,
            messageEn: $messageEn,
        );
    }

    /** @return array<string, array{string, string}> */
    public static function blankMessages(): array
    {
        return [
            'Arabic blank' => ['   ', 'Scheduled maintenance'],
            'English blank' => ['صيانة مجدولة', '   '],
        ];
    }

    public function test_window_becomes_inactive_automatically_after_its_end(): void
    {
        $window = new MaintenanceWindow(
            id: '019f8e3b-3368-7192-85a6-3da3949fd751',
            startsAt: new DateTimeImmutable('2026-07-23T10:00:00+03:00'),
            endsAt: new DateTimeImmutable('2026-07-23T11:00:00+03:00'),
            messageAr: 'صيانة مجدولة',
            messageEn: 'Scheduled maintenance',
        );

        $this->assertTrue($window->isActiveAt(new DateTimeImmutable('2026-07-23T10:30:00+03:00')));
        $this->assertFalse($window->isActiveAt(new DateTimeImmutable('2026-07-23T11:00:00+03:00')));
    }
}
