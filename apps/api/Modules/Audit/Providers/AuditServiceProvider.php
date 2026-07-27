<?php

declare(strict_types=1);

namespace Modules\Audit\Providers;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Modules\Audit\Contracts\QueryAuditActivity;
use Modules\Audit\Contracts\RecordAuditEvent;
use Modules\Audit\Domain\AuditEventCanonicalizer;
use Modules\Audit\Domain\AuditIntegrityHasher;
use Modules\Audit\Domain\AuditRetentionPolicy;
use Modules\Audit\Domain\SensitiveValueRedactor;
use Modules\Audit\Features\Retention\Console\PurgeExpiredAuditEventsCommand;
use Modules\Audit\Features\Retention\Handler\PurgeExpiredAuditEvents;
use Modules\Audit\Infrastructure\Persistence\AuditExportReadStore;
use Modules\Audit\Infrastructure\Persistence\AuditExportRepository;
use Modules\Audit\Infrastructure\Persistence\AuditIdempotencyStore;
use Modules\Audit\Infrastructure\Persistence\AuditIntegrityRepository;
use Modules\Audit\Infrastructure\Persistence\DatabaseQueryAuditActivity;
use Modules\Audit\Infrastructure\Persistence\DatabaseRecordAuditEvent;
use Shared\Contracts\TransactionalOutbox;

final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/audit.php'), 'audit');

        $this->app->singleton(SensitiveValueRedactor::class);
        $this->app->singleton(AuditEventCanonicalizer::class);
        $this->app->singleton(AuditIntegrityHasher::class, function (): AuditIntegrityHasher {
            return new AuditIntegrityHasher($this->integrityKeys());
        });
        $this->app->singleton(AuditRetentionPolicy::class, function (): AuditRetentionPolicy {
            $floorDays = config('audit.retention.floor_days');
            if (! is_int($floorDays)) {
                throw new InvalidArgumentException('audit_retention_floor_invalid');
            }

            return new AuditRetentionPolicy($floorDays);
        });

        $this->app->singleton(RecordAuditEvent::class, function ($app): RecordAuditEvent {
            $hasher = $app->make(AuditIntegrityHasher::class);
            $activeKeyVersion = config('audit.integrity.active_key_version');
            if (! is_string($activeKeyVersion)
                || ! array_key_exists($activeKeyVersion, $this->integrityKeys())) {
                throw new InvalidArgumentException('audit_integrity_key_version_unavailable');
            }

            return new DatabaseRecordAuditEvent(
                $app->make(TransactionalOutbox::class),
                $app->make(SensitiveValueRedactor::class),
                $hasher,
                $app->make(AuditRetentionPolicy::class),
                $app->make(AuditEventCanonicalizer::class),
                $activeKeyVersion,
            );
        });

        $this->app->singleton(QueryAuditActivity::class, DatabaseQueryAuditActivity::class);

        $this->app->singleton(AuditIdempotencyStore::class);
        $this->app->singleton(AuditExportReadStore::class);
        $this->app->singleton(AuditExportRepository::class);
        $this->app->singleton(AuditIntegrityRepository::class, function ($app): AuditIntegrityRepository {
            return new AuditIntegrityRepository(
                $app->make(AuditEventCanonicalizer::class),
                $app->make(AuditIntegrityHasher::class),
                $app->make(TransactionalOutbox::class),
            );
        });

        $this->app->singleton(PurgeExpiredAuditEvents::class, function ($app): PurgeExpiredAuditEvents {
            return new PurgeExpiredAuditEvents(
                $app->make(AuditIntegrityRepository::class),
                $app->make(RecordAuditEvent::class),
                $app->make(AuditRetentionPolicy::class),
            );
        });
    }

    /** @return array<string, string> */
    private function integrityKeys(): array
    {
        $keys = config('audit.integrity.keys');
        if (! is_array($keys)) {
            throw new InvalidArgumentException('audit_integrity_keys_required');
        }

        return $keys;
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PurgeExpiredAuditEventsCommand::class]);
        }
    }
}
