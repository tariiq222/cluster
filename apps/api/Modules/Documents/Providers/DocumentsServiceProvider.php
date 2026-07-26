<?php

namespace Modules\Documents\Providers;

use App\Http\Authentication\SessionPrincipalResolver;
use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;
use Modules\Documents\Application\DocumentDownloadService;
use Modules\Documents\Application\DocumentLinkService;
use Modules\Documents\Contracts\DocumentAuthorizationFactsReader;
use Modules\Documents\Contracts\DocumentDownloadGrantIssuer;
use Modules\Documents\Contracts\DocumentUploadStatusReader;
use Modules\Documents\Contracts\LinkDocument;
use Modules\Documents\Contracts\MalwareScanner;
use Modules\Documents\Contracts\PrivateObjectStorage;
use Modules\Documents\Contracts\SensitiveAccessEventRecorder;
use Modules\Documents\Contracts\WorkerPrincipalResolver;
use Modules\Documents\Domain\DocumentRetentionPolicy;
use Modules\Documents\Domain\DocumentUploadPolicy;
use Modules\Documents\Features\DocumentDownload\Http\DownloadDocumentController;
use Modules\Documents\Features\DocumentVersion\Http\ReconcileDocumentPromotionController;
use Modules\Documents\Features\DocumentVersion\Http\ScanDocumentVersionController;
use Modules\Documents\Features\Upload\Http\CompleteDocumentUploadController;
use Modules\Documents\Features\Upload\Http\GetDocumentUploadStatusController;
use Modules\Documents\Features\Upload\Http\InitiateDocumentUploadController;
use Modules\Documents\Infrastructure\Authorization\ConfiguredWorkerPrincipalResolver;
use Modules\Documents\Infrastructure\Persistence\DatabaseDocumentAuthorizationFactsReader;
use Modules\Documents\Infrastructure\Persistence\DatabaseDocumentUploadStatusReader;
use Modules\Documents\Infrastructure\Persistence\DatabaseSensitiveAccessEventRecorder;
use Modules\Documents\Infrastructure\Security\ClamAvConfiguration;
use Modules\Documents\Infrastructure\Security\ClamAvMalwareScanner;
use Modules\Documents\Infrastructure\Security\ClamAvSocketTransport;
use Modules\Documents\Infrastructure\Security\StreamSocketClamAvTransport;
use Modules\Documents\Infrastructure\Security\UnavailableMalwareScanner;
use Modules\Documents\Infrastructure\Storage\S3\DeterministicObjectKeyResolver;
use Modules\Documents\Infrastructure\Storage\S3\GuzzleS3RequestExecutor;
use Modules\Documents\Infrastructure\Storage\S3\ObjectKeyResolver;
use Modules\Documents\Infrastructure\Storage\S3\QuarantineObjectByteSource;
use Modules\Documents\Infrastructure\Storage\S3\S3CompatibleConfiguration;
use Modules\Documents\Infrastructure\Storage\S3\S3CompatiblePrivateObjectStorage;
use Modules\Documents\Infrastructure\Storage\S3\S3DocumentDownloadGrantIssuer;
use Modules\Documents\Infrastructure\Storage\S3\S3QuarantineObjectByteSource;
use Modules\Documents\Infrastructure\Storage\S3\S3RequestExecutor;
use Modules\Documents\Infrastructure\Storage\S3\SigV4RequestSigner;
use Modules\Documents\Infrastructure\Storage\UnavailablePrivateObjectStorage;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

final class DocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DocumentAuthorizationFactsReader::class, DatabaseDocumentAuthorizationFactsReader::class);
        $this->app->bind(DocumentUploadStatusReader::class, DatabaseDocumentUploadStatusReader::class);
        $this->app->bind(DocumentDownloadGrantIssuer::class, S3DocumentDownloadGrantIssuer::class);
        $this->app->bind(DocumentDownloadService::class);
        $this->app->bind(LinkDocument::class, DocumentLinkService::class);
        $this->app->singleton(DocumentUploadPolicy::class, fn (): DocumentUploadPolicy => DocumentUploadPolicy::fromConfig(config('documents')));
        $this->app->bind(SensitiveAccessEventRecorder::class, DatabaseSensitiveAccessEventRecorder::class);
        $this->app->singleton(DocumentRetentionPolicy::class, fn (): DocumentRetentionPolicy => DocumentRetentionPolicy::fromConfig(config('documents')));
        $this->app->singleton(WorkerPrincipalResolver::class, fn (): WorkerPrincipalResolver => new ConfiguredWorkerPrincipalResolver(
            (string) config('documents.worker.token'),
            (string) config('documents.worker.user_id'),
            (string) config('documents.worker.organization_unit_id'),
        ));
        $this->app->when([ScanDocumentVersionController::class, ReconcileDocumentPromotionController::class])
            ->needs(ResolveDevelopmentFixturePrincipal::class)
            ->give(fn ($app) => $app->make(WorkerPrincipalResolver::class));
        $this->app->when([
            InitiateDocumentUploadController::class,
            CompleteDocumentUploadController::class,
            GetDocumentUploadStatusController::class,
            DownloadDocumentController::class,
        ])->needs(ResolveDevelopmentFixturePrincipal::class)
            ->give(fn ($app) => $app->make(SessionPrincipalResolver::class));
        $this->app->singleton(S3CompatibleConfiguration::class, fn (): S3CompatibleConfiguration => S3CompatibleConfiguration::fromEnvironment(! $this->documentsRuntimeEnabled()));
        $this->app->singleton(ClamAvConfiguration::class, fn (): ClamAvConfiguration => ClamAvConfiguration::fromEnvironment(! $this->documentsRuntimeEnabled()));
        $this->app->bind(ObjectKeyResolver::class, DeterministicObjectKeyResolver::class);
        $this->app->singleton(S3RequestExecutor::class, fn (): S3RequestExecutor => new GuzzleS3RequestExecutor(new Client));
        $this->app->singleton(SigV4RequestSigner::class, function (): SigV4RequestSigner {
            $configuration = $this->app->make(S3CompatibleConfiguration::class);

            return new SigV4RequestSigner($configuration->region, $configuration->accessKeyId, $configuration->secretAccessKey);
        });
        $this->app->bind(QuarantineObjectByteSource::class, S3QuarantineObjectByteSource::class);
        $this->app->singleton(ClamAvSocketTransport::class, function (): ClamAvSocketTransport {
            $configuration = $this->app->make(ClamAvConfiguration::class);

            return new StreamSocketClamAvTransport(
                $configuration->transport,
                $configuration->host,
                $configuration->port,
                $configuration->unixSocket,
                $configuration->connectTimeoutSeconds,
                $configuration->readTimeoutSeconds,
            );
        });
        $this->app->bind(PrivateObjectStorage::class, fn (): PrivateObjectStorage => $this->documentsRuntimeEnabled()
            ? $this->app->make(S3CompatiblePrivateObjectStorage::class)
            : $this->app->make(UnavailablePrivateObjectStorage::class));
        $this->app->singleton(MalwareScanner::class, function (): MalwareScanner {
            if (! $this->documentsRuntimeEnabled()) {
                return new UnavailableMalwareScanner;
            }

            $configuration = $this->app->make(ClamAvConfiguration::class);
            if ($configuration->transport === 'disabled') {
                return new UnavailableMalwareScanner;
            }

            return new ClamAvMalwareScanner(
                $this->app->make(QuarantineObjectByteSource::class),
                $this->app->make(ClamAvSocketTransport::class),
                $configuration->engineName,
                $configuration->signatureVersion,
                $configuration->chunkBytes,
            );
        });
    }

    private function documentsProduction(): bool
    {
        $arguments = $_SERVER['argv'] ?? [];

        return app()->environment('production')
            && ! app()->runningUnitTests()
            && ! in_array('test', $arguments, true)
            && ! in_array('config:clear', $arguments, true)
            && ! in_array('package:discover', $arguments, true)
            && ! str_contains(implode(' ', $arguments), 'phpstan')
            && ! str_contains(implode(' ', $arguments), 'phpunit');
    }

    private function documentsRuntimeEnabled(): bool
    {
        return $this->documentsProduction()
            || (app()->environment('testing') && config('documents.runtime.testing_enabled') === true);
    }
}
