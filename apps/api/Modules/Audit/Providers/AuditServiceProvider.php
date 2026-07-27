<?php

declare(strict_types=1);

namespace Modules\Audit\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Audit\Contracts\QueryAuditActivity;
use Modules\Audit\Contracts\RecordAuditEvent;
use Modules\Audit\Domain\AuditIntegrityHasher;
use Modules\Audit\Domain\AuditRetentionPolicy;
use Modules\Audit\Domain\SensitiveValueRedactor;
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
        $this->app->singleton(AuditIntegrityHasher::class);
        $this->app->singleton(AuditRetentionPolicy::class, function () {
            $overrides = (array) config('audit.retention', []);

            return new AuditRetentionPolicy($overrides);
        });

        $this->app->singleton(RecordAuditEvent::class, function ($app) {
            return new DatabaseRecordAuditEvent(
                $app->make(TransactionalOutbox::class),
                $app->make(SensitiveValueRedactor::class),
                $app->make(AuditIntegrityHasher::class),
                $app->make(AuditRetentionPolicy::class),
            );
        });

        $this->app->singleton(QueryAuditActivity::class, DatabaseQueryAuditActivity::class);

        $this->app->singleton(AuditIdempotencyStore::class);
        $this->app->singleton(AuditExportRepository::class);
        $this->app->singleton(AuditIntegrityRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Persistence/Migrations');
    }
}
