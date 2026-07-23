<?php

namespace Modules\PlatformSettings\Features\Settings\Handler;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\PlatformSettings\Domain\SettingKey;
use Modules\PlatformSettings\Domain\SettingsVersion;
use Modules\PlatformSettings\Infrastructure\Outbox\PlatformSettingsOutbox;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

final class PlatformSettingsHandler
{
    public function __construct(private readonly PlatformSettingsOutbox $outbox) {}

    /** @return array<string, mixed> */
    public function createDraft(): array
    {
        return DB::transaction(function (): array {
            $existing = DB::table('platform_setting_versions')
                ->whereIn('status', ['draft', 'validated'])
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                throw new ConflictHttpException('An editable platform settings version already exists.');
            }

            $version = SettingsVersion::defaults(Str::uuid7()->toString());
            $now = now();
            DB::table('platform_setting_versions')->insert([
                'id' => $version->id,
                'status' => $version->status,
                'settings_document' => json_encode($version->document(), JSON_THROW_ON_ERROR),
                'content_hash' => $version->contentHash(),
                'published_at' => null,
                'lock_version' => $version->lockVersion,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->persistValues($version, $now);

            return $this->present($version);
        });
    }

    /** @return array<string, mixed> */
    public function setValue(string $versionId, string $key, mixed $value, int $expectedLockVersion): array
    {
        return DB::transaction(function () use ($versionId, $key, $value, $expectedLockVersion): array {
            $row = $this->lockedRow($versionId);
            $this->assertEtag($row, $expectedLockVersion);
            $version = $this->versionFromRow($row)->withValue($key, $value);
            $nextLockVersion = $expectedLockVersion + 1;
            $now = now();
            $updated = DB::table('platform_setting_versions')
                ->where('id', $versionId)
                ->where('lock_version', $expectedLockVersion)
                ->update([
                    'settings_document' => json_encode($version->document(), JSON_THROW_ON_ERROR),
                    'content_hash' => $version->contentHash(),
                    'lock_version' => $nextLockVersion,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new PreconditionFailedHttpException('If-Match does not match the current settings version.');
            }
            $this->persistValues($version, $now);

            return $this->present(new SettingsVersion($version->id, $version->status, $version->defaultLocale, $nextLockVersion, $version->security, $version->timezone, $version->activeLogMonths));
        });
    }

    /** @return array<string, mixed> */
    public function validate(string $versionId, int $expectedLockVersion): array
    {
        return DB::transaction(function () use ($versionId, $expectedLockVersion): array {
            $row = $this->lockedRow($versionId);
            $this->assertEtag($row, $expectedLockVersion);
            $version = $this->versionFromRow($row)->validate();
            $nextLockVersion = $expectedLockVersion + 1;
            $updated = DB::table('platform_setting_versions')
                ->where('id', $versionId)
                ->where('lock_version', $expectedLockVersion)
                ->update(['status' => 'validated', 'lock_version' => $nextLockVersion, 'updated_at' => now()]);
            if ($updated !== 1) {
                throw new PreconditionFailedHttpException('If-Match does not match the current settings version.');
            }

            return $this->present(new SettingsVersion($version->id, $version->status, $version->defaultLocale, $nextLockVersion, $version->security, $version->timezone, $version->activeLogMonths));
        });
    }

    /** @return array<string, mixed> */
    public function publish(string $versionId, int $expectedLockVersion): array
    {
        return DB::transaction(function () use ($versionId, $expectedLockVersion): array {
            $row = $this->lockedRow($versionId);
            $this->assertEtag($row, $expectedLockVersion);
            if ((string) $row->status !== 'validated') {
                throw new ConflictHttpException('Only a validated settings version can be published.');
            }
            $version = $this->versionFromRow($row)->validate();
            $published = DB::table('platform_setting_versions')->where('status', 'published')->lockForUpdate()->get();
            if ($published->count() > 1) {
                throw new ConflictHttpException('More than one published settings version exists.');
            }
            $now = now();
            if ($published->isNotEmpty()) {
                DB::table('platform_setting_versions')->where('id', $published->first()->id)->update([
                    'status' => 'retired',
                    'updated_at' => $now,
                ]);
            }
            $contentHash = $version->contentHash();
            $updated = DB::table('platform_setting_versions')
                ->where('id', $versionId)
                ->where('lock_version', $expectedLockVersion)
                ->update([
                    'status' => 'published',
                    'content_hash' => $contentHash,
                    'published_at' => $now,
                    'lock_version' => $expectedLockVersion + 1,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new PreconditionFailedHttpException('If-Match does not match the current settings version.');
            }
            $this->outbox->append($versionId, $contentHash);

            return $this->present(new SettingsVersion($version->id, 'published', $version->defaultLocale, $expectedLockVersion + 1, $version->security, $version->timezone, $version->activeLogMonths));
        });
    }

    /** @return array<string, mixed> */
    public function current(): array
    {
        $row = DB::table('platform_setting_versions')->where('status', 'published')->orderByDesc('published_at')->first();
        if ($row === null) {
            return ['version_id' => null, 'default_locale' => 'ar', 'timezone' => SettingsVersion::TIMEZONE, 'security' => SettingsVersion::defaults('defaults')->security];
        }

        return ['version_id' => (string) $row->id] + $this->present($this->versionFromRow($row));
    }

    /** @return list<array<string, mixed>> */
    public function listVersions(): array
    {
        return DB::table('platform_setting_versions')->orderByDesc('created_at')->get()
            ->map(fn (object $row): array => $this->present($this->versionFromRow($row)))
            ->all();
    }

    private function lockedRow(string $versionId): object
    {
        $row = DB::table('platform_setting_versions')->where('id', $versionId)->lockForUpdate()->first();
        if ($row === null) {
            throw new NotFoundHttpException('Platform settings version was not found.');
        }

        return $row;
    }

    private function assertEtag(object $row, int $expectedLockVersion): void
    {
        if ((int) $row->lock_version !== $expectedLockVersion) {
            throw new PreconditionFailedHttpException('If-Match does not match the current settings version.');
        }
    }

    private function versionFromRow(object $row): SettingsVersion
    {
        /** @var array<string, mixed> $document */
        $document = json_decode((string) $row->settings_document, true, 512, JSON_THROW_ON_ERROR);

        return SettingsVersion::fromDocument((string) $row->id, (string) $row->status, (int) $row->lock_version, $document);
    }

    private function persistValues(SettingsVersion $version, mixed $now): void
    {
        foreach (SettingKey::cases() as $key) {
            $value = match ($key) {
                SettingKey::DefaultLocale => $version->defaultLocale,
                SettingKey::ActiveLogMonths => $version->activeLogMonths,
                default => $version->security[$key->name()],
            };
            DB::table('platform_settings')->updateOrInsert([
                'platform_setting_version_id' => $version->id,
                'setting_key' => $key->value,
            ], [
                'id' => Str::uuid7()->toString(),
                'setting_value' => json_encode($value, JSON_THROW_ON_ERROR),
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function present(SettingsVersion $version): array
    {
        return [
            'id' => $version->id,
            'status' => $version->status,
            'lock_version' => $version->lockVersion,
            'content_hash' => $version->contentHash(),
            'default_locale' => $version->defaultLocale,
            'timezone' => SettingsVersion::TIMEZONE,
            'security' => $version->security,
            'active_log_months' => $version->activeLogMonths,
        ];
    }
}
