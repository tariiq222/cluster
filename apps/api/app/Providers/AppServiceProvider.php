<?php

namespace App\Providers;

use App\Http\Authentication\SessionPrincipalResolver;
use App\Http\Controllers\Documents\CompleteDocumentUploadController;
use App\Http\Controllers\Documents\GetDocumentUploadStatusController;
use App\Http\Controllers\Documents\InitiateDocumentUploadController;
use App\Http\Controllers\Documents\ReconcileDocumentPromotionController;
use App\Http\Controllers\Documents\ScanDocumentVersionController;
use App\Http\Controllers\Organization\CreateTemporaryAssignmentController;
use App\Http\Controllers\Organization\GetTemporaryAssignmentController;
use App\Http\Controllers\Organization\ListTemporaryAssignmentsController;
use App\Http\Controllers\Organization\RevokeTemporaryAssignmentController;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\ServiceProvider;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Infrastructure\FixtureFacilityDecision;
use Modules\Documents\Contracts\DocumentAuthorizationFactsReader;
use Modules\Documents\Contracts\DocumentUploadStatusReader;
use Modules\Documents\Contracts\MalwareScanner;
use Modules\Documents\Contracts\PrivateObjectStorage;
use Modules\Documents\Contracts\WorkerPrincipalResolver;
use Modules\Documents\Domain\DocumentRetentionPolicy;
use Modules\Documents\Domain\DocumentUploadPolicy;
use Modules\Documents\Infrastructure\Authorization\ConfiguredWorkerPrincipalResolver;
use Modules\Documents\Infrastructure\Persistence\DatabaseDocumentAuthorizationFactsReader;
use Modules\Documents\Infrastructure\Persistence\DatabaseDocumentUploadStatusReader;
use Modules\Documents\Infrastructure\Security\ClamAvConfiguration;
use Modules\Documents\Infrastructure\Security\ClamAvMalwareScanner;
use Modules\Documents\Infrastructure\Security\ClamAvSocketTransport;
use Modules\Documents\Infrastructure\Security\StreamSocketClamAvTransport;
use Modules\Documents\Infrastructure\Security\UnavailableMalwareScanner;
use Modules\Documents\Infrastructure\Storage\PrivateDocumentDiskConfiguration;
use Modules\Documents\Infrastructure\Storage\S3\DeterministicObjectKeyResolver;
use Modules\Documents\Infrastructure\Storage\S3\GuzzleS3RequestExecutor;
use Modules\Documents\Infrastructure\Storage\S3\ObjectKeyResolver;
use Modules\Documents\Infrastructure\Storage\S3\QuarantineObjectByteSource;
use Modules\Documents\Infrastructure\Storage\S3\S3CompatibleConfiguration;
use Modules\Documents\Infrastructure\Storage\S3\S3CompatiblePrivateObjectStorage;
use Modules\Documents\Infrastructure\Storage\S3\S3QuarantineObjectByteSource;
use Modules\Documents\Infrastructure\Storage\S3\S3RequestExecutor;
use Modules\Documents\Infrastructure\Storage\S3\SigV4RequestSigner;
use Modules\Documents\Infrastructure\Storage\UnavailablePrivateObjectStorage;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Identity\Features\Activation\Contracts\IssueActivationToken;
use Modules\Identity\Features\Activation\Handler\ActivationHandler;
use Modules\Identity\Features\Authentication\Contracts\AuthenticateUser;
use Modules\Identity\Features\Authentication\Contracts\PreAuthThrottle;
use Modules\Identity\Features\Authentication\Handler\AuthenticationHandler;
use Modules\Identity\Features\Credentials\Contracts\ChangePassword;
use Modules\Identity\Features\Credentials\Handler\CredentialHandler;
use Modules\Identity\Features\ResolveDevelopmentFixturePrincipal\Http\DevelopmentFixturePrincipalResolver;
use Modules\Identity\Features\Sessions\Contracts\ResolveSession;
use Modules\Identity\Features\Sessions\Handler\SessionHandler;
use Modules\Identity\Infrastructure\Security\PersistentPreAuthThrottle;
use Modules\Organization\Contracts\ResolveQuarantinedImport;
use Modules\Organization\Contracts\ValidatePersonReference;
use Modules\Organization\Features\TemporaryAssignment\Console\ExpireTemporaryAssignmentsCommand;
use Modules\Organization\Features\TemporaryAssignment\Console\HandlerTemporaryAssignmentExpiration;
use Modules\Organization\Features\TemporaryAssignment\Console\RunTemporaryAssignmentExpiration;
use Modules\Organization\Features\TemporaryAssignment\Contracts\ValidateTemporaryAssignmentCapabilities;
use Modules\Organization\Features\TemporaryAssignment\Events\BuildTemporaryAssignmentEvent;
use Modules\Organization\Features\TemporaryAssignment\Events\TemporaryAssignmentEventFactory;
use Modules\Organization\Features\TemporaryAssignment\Http\DatabaseTemporaryAssignmentHttpGateway;
use Modules\Organization\Features\TemporaryAssignment\Http\TemporaryAssignmentHttpGateway;
use Modules\Organization\Infrastructure\Authorization\ConfiguredTemporaryAssignmentCapabilityValidator;
use Modules\Organization\Infrastructure\Import\UnavailableQuarantinedImport;
use Modules\Organization\Infrastructure\Persistence\ValidatePersonReferenceFromPersistence;
use Modules\WorkDefinitions\Contracts\ResolvePublishedRequestFixture;
use Modules\WorkDefinitions\Infrastructure\ResolvePublishedRequestFixtureFromPersistence;
use Modules\Workflow\Contracts\AdvanceWorkflowStep;
use Modules\Workflow\Infrastructure\Persistence\WorkflowStepAdvancer;
use Predis\Client;
use Shared\Contracts\TransactionalOutbox;
use Shared\Infrastructure\Outbox\DatabaseTransactionalOutbox;
use Shared\Infrastructure\Streams\PredisRedisStreamTransport;
use Shared\Infrastructure\Streams\RedisStreamTransport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DecideAccess::class, FixtureFacilityDecision::class);
        $this->app->bind(ResolvePublishedRequestFixture::class, ResolvePublishedRequestFixtureFromPersistence::class);
        $this->app->bind(TransactionalOutbox::class, DatabaseTransactionalOutbox::class);
        $this->app->bind(AdvanceWorkflowStep::class, WorkflowStepAdvancer::class);
        $this->app->bind(ResolveQuarantinedImport::class, UnavailableQuarantinedImport::class);
        $this->app->bind(ValidatePersonReference::class, ValidatePersonReferenceFromPersistence::class);
        $this->app->bind(AuthenticateUser::class, AuthenticationHandler::class);
        $this->app->bind(PreAuthThrottle::class, PersistentPreAuthThrottle::class);
        $this->app->bind(IssueActivationToken::class, ActivationHandler::class);
        $this->app->bind(ChangePassword::class, CredentialHandler::class);
        $this->app->bind(ResolveSession::class, SessionHandler::class);
        $this->app->bind(TemporaryAssignmentHttpGateway::class, DatabaseTemporaryAssignmentHttpGateway::class);
        $this->app->bind(RunTemporaryAssignmentExpiration::class, HandlerTemporaryAssignmentExpiration::class);
        $this->app->bind(BuildTemporaryAssignmentEvent::class, TemporaryAssignmentEventFactory::class);
        $this->app->bind(ValidateTemporaryAssignmentCapabilities::class, ConfiguredTemporaryAssignmentCapabilityValidator::class);
        $this->app->bind(DocumentAuthorizationFactsReader::class, DatabaseDocumentAuthorizationFactsReader::class);
        $this->app->bind(DocumentUploadStatusReader::class, DatabaseDocumentUploadStatusReader::class);
        $this->app->singleton(DocumentUploadPolicy::class, fn (): DocumentUploadPolicy => DocumentUploadPolicy::fromConfig(config('documents')));
        $this->app->singleton(DocumentRetentionPolicy::class, fn (): DocumentRetentionPolicy => DocumentRetentionPolicy::fromConfig(config('documents')));
        $this->app->singleton(ResolveDevelopmentFixturePrincipal::class, DevelopmentFixturePrincipalResolver::class);
        $this->app->singleton(SessionPrincipalResolver::class);
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
            CreateTemporaryAssignmentController::class,
            ListTemporaryAssignmentsController::class,
            GetTemporaryAssignmentController::class,
            RevokeTemporaryAssignmentController::class,
        ])->needs(ResolveDevelopmentFixturePrincipal::class)
            ->give(fn ($app) => $app->make(SessionPrincipalResolver::class));
        $this->app->singleton(S3CompatibleConfiguration::class, fn (): S3CompatibleConfiguration => S3CompatibleConfiguration::fromEnvironment(! $this->documentsRuntimeEnabled()));
        $this->app->singleton(ClamAvConfiguration::class, fn (): ClamAvConfiguration => ClamAvConfiguration::fromEnvironment(! $this->documentsRuntimeEnabled()));
        $this->app->bind(ObjectKeyResolver::class, DeterministicObjectKeyResolver::class);
        $this->app->singleton(S3RequestExecutor::class, fn (): S3RequestExecutor => new GuzzleS3RequestExecutor(new GuzzleClient));
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
        $this->app->singleton(RedisStreamTransport::class, function (): RedisStreamTransport {
            $url = config('database.redis.default.url');
            if (is_string($url) && $url !== '') {
                return new PredisRedisStreamTransport(new Client($url));
            }

            $parameters = [
                'scheme' => 'tcp',
                'host' => config('database.redis.default.host', '127.0.0.1'),
                'port' => (int) config('database.redis.default.port', 6379),
                'database' => (int) config('database.redis.default.database', 0),
            ];
            foreach (['username', 'password'] as $credential) {
                $value = config("database.redis.default.{$credential}");
                if (is_string($value) && $value !== '') {
                    $parameters[$credential] = $value;
                }
            }

            return new PredisRedisStreamTransport(new Client($parameters));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom([
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/CreateOrganizationCoreTables.php'),
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/SeedOrganizationFacilityTypes.php'),
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/CreateOrganizationTreeTables.php'),
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/CreateOrganizationSupervisoryRelationshipTables.php'),
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/SeedOrganizationUnitTypes.php'),
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/CreateOrganizationPeopleTable.php'),
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/CreateOrganizationWorkforceAssignmentsTable.php'),
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/CreateOrganizationZImportTables.php'),
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/ZCreateOrganizationTemporaryAssignmentsTable.php'),
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/ZCreateDevelopmentFacilitiesTable.php'),
            base_path('Modules/Identity/Infrastructure/Persistence/Migrations/ZCreateDevelopmentFixtureAccountsTable.php'),
            base_path('Modules/Identity/Infrastructure/Persistence/Migrations/CreateIdentityAccountTables.php'),
            base_path('Modules/Identity/Infrastructure/Persistence/Migrations/ZAddIdentityCredentialCoreTables.php'),
            base_path('Modules/Authorization/Infrastructure/Persistence/Migrations/CreateAuthorizationRbacDataTables.php'),
            base_path('Modules/Authorization/Infrastructure/Persistence/Migrations/CreateAuthorizationExplicitDenyTables.php'),
            base_path('Modules/Authorization/Infrastructure/Persistence/Migrations/CreateAuthorizationFieldAuditTables.php'),
            base_path('Modules/Authorization/Infrastructure/Persistence/Migrations/ZAddAuthorizationHttpTables.php'),
            base_path('Modules/Documents/Infrastructure/Persistence/Migrations/CreateDocumentsCoreTables.php'),
            base_path('Modules/Documents/Infrastructure/Persistence/Migrations/HardenDocumentUploadSecurityTables.php'),
            base_path('Modules/Documents/Infrastructure/Persistence/Migrations/ZZAddDocumentUploadPurpose.php'),
            base_path('Modules/WorkDefinitions/Infrastructure/Persistence/Migrations/CreateDevelopmentWorkTypeFixturesTable.php'),
            base_path('Modules/WorkRecords/Infrastructure/Persistence/Migrations/CreateWorkRecordsTable.php'),
            base_path('Modules/WorkRecords/Infrastructure/Outbox/Migrations/CreateOutboxTable.php'),
            base_path('Modules/Workflow/Infrastructure/Persistence/Migrations/CreateWorkflowTables.php'),
            base_path('Modules/Tasks/Infrastructure/Persistence/Migrations/CreateTasksTable.php'),
            base_path('Modules/Notifications/Infrastructure/Persistence/Migrations/CreateNotificationInboxTable.php'),
            base_path('Modules/Notifications/Infrastructure/Persistence/Migrations/CreateNotificationsTable.php'),
        ]);
        $this->commands([ExpireTemporaryAssignmentsCommand::class]);

        if ($this->documentsRuntimeEnabled()) {
            if (config('documents.storage.upload_endpoint_allowlist') === []) {
                throw new \RuntimeException('Documents upload endpoint allowlist is required outside testing.');
            }

            if ($this->documentsProduction()) {
                $this->assertDocumentsStorageRuntimeSafe();
            }
            $this->app->make(S3CompatibleConfiguration::class);
            $this->app->make(ClamAvConfiguration::class);
            $this->app->make(WorkerPrincipalResolver::class);
        }
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

    private function assertDocumentsStorageRuntimeSafe(): void
    {
        $quarantine = config('filesystems.disks.documents-quarantine');
        $available = config('filesystems.disks.documents-available');

        PrivateDocumentDiskConfiguration::assertRuntimeSafe(false, [
            'key' => $quarantine['key'] ?? null,
            'secret' => $quarantine['secret'] ?? null,
            'region' => $quarantine['region'] ?? null,
            'bucket' => $quarantine['bucket'] ?? null,
            'kms_key_id' => $quarantine['options']['SSEKMSKeyId'] ?? null,
        ], [
            'key' => $available['key'] ?? null,
            'secret' => $available['secret'] ?? null,
            'region' => $available['region'] ?? null,
            'bucket' => $available['bucket'] ?? null,
            'kms_key_id' => $available['options']['SSEKMSKeyId'] ?? null,
        ]);
    }
}
