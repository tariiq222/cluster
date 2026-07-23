<?php

namespace Modules\PlatformSettings\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PlatformSettings\Contracts\GetEffectivePlatformSettings;
use Modules\PlatformSettings\Features\Settings\Handler\PlatformSettingsHandler;
use Modules\PlatformSettings\Infrastructure\Outbox\PlatformSettingsOutbox;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Tests\TestCase;

final class PlatformSettingsLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_one_editable_draft_is_allowed(): void
    {
        $handler = $this->app->make(PlatformSettingsHandler::class);
        $handler->createDraft();

        $this->expectException(ConflictHttpException::class);
        $handler->createDraft();
    }

    public function test_publish_requires_a_current_etag_and_a_validated_version(): void
    {
        $handler = $this->app->make(PlatformSettingsHandler::class);
        $draft = $handler->createDraft();
        $handler->setValue($draft['id'], 'security.minimum_password_length', 14, $draft['lock_version']);

        $this->expectException(PreconditionFailedHttpException::class);
        $handler->publish($draft['id'], $draft['lock_version']);
    }

    public function test_publish_rejects_a_draft_even_when_its_etag_matches(): void
    {
        $handler = $this->app->make(PlatformSettingsHandler::class);
        $draft = $handler->createDraft();

        $this->expectException(ConflictHttpException::class);
        $handler->publish($draft['id'], $draft['lock_version']);
    }

    public function test_outbox_failure_rolls_back_the_publish(): void
    {
        $this->app->instance(PlatformSettingsOutbox::class, new class extends PlatformSettingsOutbox
        {
            public function append(string $versionId, string $contentHash): void
            {
                throw new RuntimeException('outbox unavailable');
            }
        });
        $handler = $this->app->make(PlatformSettingsHandler::class);
        $draft = $handler->createDraft();
        $validated = $handler->validate($draft['id'], $draft['lock_version']);

        try {
            $handler->publish($validated['id'], $validated['lock_version']);
            $this->fail('Expected outbox failure.');
        } catch (RuntimeException) {
            $this->assertSame('validated', $handler->listVersions()[0]['status']);
            $this->assertNull($handler->current()['version_id']);
        }
    }

    public function test_current_returns_only_the_last_published_version_and_the_bound_contract(): void
    {
        $handler = $this->app->make(PlatformSettingsHandler::class);
        $first = $handler->createDraft();
        $first = $handler->validate($first['id'], $first['lock_version']);
        $first = $handler->publish($first['id'], $first['lock_version']);

        $second = $handler->createDraft();
        $second = $handler->setValue($second['id'], 'security.minimum_password_length', 14, $second['lock_version']);
        $second = $handler->validate($second['id'], $second['lock_version']);
        $second = $handler->publish($second['id'], $second['lock_version']);

        $current = $handler->current();
        $this->assertSame($second['id'], $current['version_id']);
        $this->assertSame(14, $current['security']['minimum_password_length']);
        $this->assertSame('Asia/Riyadh', $current['timezone']);
        $effective = $this->app->make(GetEffectivePlatformSettings::class)->current();
        $this->assertSame('ar', $effective['default_locale']);
        $this->assertSame('Asia/Riyadh', $effective['timezone']);
        $this->assertSame(14, $effective['security']['minimum_password_length']);
        $versions = collect($handler->listVersions())->keyBy('id');
        $this->assertSame('retired', $versions[$first['id']]['status']);
    }
}
