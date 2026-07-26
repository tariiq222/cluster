<?php

namespace Modules\PlatformSettings\Features\Maintenance\Handler;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\PlatformSettings\Domain\MaintenanceWindow;

final class MaintenanceWindowHandler
{
    public function schedule(
        string $createdBy,
        DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
        string $messageAr,
        string $messageEn,
    ): MaintenanceWindow {
        $window = new MaintenanceWindow(Str::uuid7()->toString(), $startsAt, $endsAt, $messageAr, $messageEn);

        return DB::transaction(function () use ($window, $createdBy): MaintenanceWindow {
            $now = now();
            DB::table('platform_maintenance_windows')->insert([
                'id' => $window->id,
                'status' => $window->status,
                'starts_at' => $window->startsAt,
                'ends_at' => $window->endsAt,
                // The existing schema owns one reason field. A structured value retains both mandatory locale messages.
                'reason' => json_encode(['ar' => $window->messageAr, 'en' => $window->messageEn], JSON_THROW_ON_ERROR),
                'created_by' => $createdBy,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $window;
        });
    }

    public function activeAt(DateTimeImmutable $now): ?MaintenanceWindow
    {
        $row = DB::table('platform_maintenance_windows')
            ->whereIn('status', ['scheduled', 'active'])
            ->where('starts_at', '<=', $now)
            ->where(static fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderByDesc('starts_at')
            ->first();
        if ($row === null) {
            return null;
        }

        $messages = json_decode((string) $row->reason, true);
        if (! is_array($messages) || ! is_string($messages['ar'] ?? null) || ! is_string($messages['en'] ?? null)) {
            return null;
        }

        return new MaintenanceWindow(
            id: (string) $row->id,
            startsAt: new DateTimeImmutable((string) $row->starts_at),
            endsAt: $row->ends_at === null ? null : new DateTimeImmutable((string) $row->ends_at),
            messageAr: $messages['ar'],
            messageEn: $messages['en'],
            status: (string) $row->status,
        );
    }
}
