<?php

namespace App\Providers;

use App\Http\Authentication\SessionPrincipalResolver;
use App\Http\Controllers\Documents\CompleteDocumentUploadController;
use App\Http\Controllers\Documents\DownloadDocumentController;
use App\Http\Controllers\Documents\GetDocumentUploadStatusController;
use App\Http\Controllers\Documents\InitiateDocumentUploadController;
use App\Http\Controllers\Documents\ReconcileDocumentPromotionController;
use App\Http\Controllers\Documents\ScanDocumentVersionController;
use App\Http\Controllers\Organization\CreateTemporaryAssignmentController;
use App\Http\Controllers\Organization\GetTemporaryAssignmentController;
use App\Http\Controllers\Organization\ListTemporaryAssignmentsController;
use App\Http\Controllers\Organization\RevokeTemporaryAssignmentController;
use App\Integrations\Notifications\DatabaseTechnicalAlertRecipientResolver;
use App\Integrations\PlatformOperations\CommandBackupOperationsGateway;
use App\Integrations\PlatformOperations\LaravelPlatformHealthGateway;
use App\Integrations\PlatformOperations\ObjectStorageTechnicalLogArchive;
use App\Integrations\PlatformOperations\TechnicalLogSourceUnavailable;
use App\Integrations\PlatformOperations\UnavailableTechnicalLogSource;
use App\Integrations\PlatformSettings\CatalogTechnicalAlertRecipientCapabilityValidator;
use App\Integrations\PlatformSettings\PlatformSettingsApi;
use App\Integrations\WorkRecordAuthorizationFacts;
use App\Integrations\WorkRecordWorkflowSourceAuthorizationFacts;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\ServiceProvider;
use Modules\Authorization\Contracts\CountOperationsOfficeMembers;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\PersistAccessDecision;
use Modules\Authorization\Contracts\ResolveAuthorizationSimulationFacts;
use Modules\Authorization\Infrastructure\BootstrapGatedDecideAccess;
use Modules\Authorization\Infrastructure\Persistence\CountOperationsOfficeMembers as DatabaseCountOperationsOfficeMembers;
use Modules\Authorization\Infrastructure\Persistence\DatabasePersistAccessDecision;
use Modules\Authorization\Infrastructure\RbacAbacDecideAccess;
use Modules\Authorization\Infrastructure\Simulation\RegisteredAuthorizationSimulationFactsResolver;
use Modules\Documents\Application\DocumentDownloadService;
use Modules\Documents\Contracts\DocumentAuthorizationFactsReader;
use Modules\Documents\Contracts\DocumentDownloadGrantIssuer;
use Modules\Documents\Contracts\DocumentUploadStatusReader;
use Modules\Documents\Contracts\LinkedResourceAuthorizationFacts;
use Modules\Documents\Contracts\MalwareScanner;
use Modules\Documents\Contracts\PrivateObjectStorage;
use Modules\Documents\Contracts\SensitiveAccessEventRecorder;
use Modules\Documents\Contracts\WorkerPrincipalResolver;
use Modules\Documents\Domain\DocumentRetentionPolicy;
use Modules\Documents\Domain\DocumentUploadPolicy;
use Modules\Documents\Infrastructure\Authorization\ConfiguredWorkerPrincipalResolver;
use Modules\Documents\Infrastructure\Persistence\DatabaseDocumentAuthorizationFactsReader;
use Modules\Documents\Infrastructure\Persistence\DatabaseDocumentUploadStatusReader;
use Modules\Documents\Infrastructure\Persistence\DatabaseSensitiveAccessEventRecorder;
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
use Modules\Documents\Infrastructure\Storage\S3\S3DocumentDownloadGrantIssuer;
use Modules\Documents\Infrastructure\Storage\S3\S3QuarantineObjectByteSource;
use Modules\Documents\Infrastructure\Storage\S3\S3RequestExecutor;
use Modules\Documents\Infrastructure\Storage\S3\SigV4RequestSigner;
use Modules\Documents\Infrastructure\Storage\UnavailablePrivateObjectStorage;
use Modules\Identity\Contracts\ResolveAccountEntitlement;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Modules\Identity\Contracts\ResolveUserForPerson;
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
use Modules\Identity\Infrastructure\DatabaseResolveAccountEntitlement;
use Modules\Identity\Infrastructure\Persistence\ResolveUserForPerson as DatabaseResolveUserForPerson;
use Modules\Identity\Infrastructure\Security\PersistentPreAuthThrottle;
use Modules\Identity\Infrastructure\SessionPrincipalContextResolver;
use Modules\Notifications\Contracts\ResolveTechnicalAlertRecipients;
use Modules\Organization\Contracts\GetActiveSupervisoryRelationships;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use Modules\Organization\Contracts\ResolvePersonOrganizationScope;
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
use Modules\Organization\Infrastructure\Persistence\DatabaseGetActiveSupervisoryRelationships;
use Modules\Organization\Infrastructure\Persistence\DatabaseResolveOrganizationScopeAncestry;
use Modules\Organization\Infrastructure\Persistence\DatabaseResolvePersonOrganizationScope;
use Modules\Organization\Infrastructure\Persistence\ValidatePersonReferenceFromPersistence;
use Modules\PlatformSettings\Contracts\BackupOperationsGateway;
use Modules\PlatformSettings\Contracts\GetEffectivePlatformSettings;
use Modules\PlatformSettings\Contracts\PublishTechnicalAlert;
use Modules\PlatformSettings\Contracts\ResolveBusinessCalendar;
use Modules\PlatformSettings\Contracts\TechnicalLogArchive;
use Modules\PlatformSettings\Contracts\TechnicalLogArchiveStore;
use Modules\PlatformSettings\Contracts\TechnicalLogSource;
use Modules\PlatformSettings\Contracts\ValidateTechnicalAlertRecipientCapability;
use Modules\PlatformSettings\Features\Alerts\Handler\AlertPolicyHandler;
use Modules\PlatformSettings\Features\Operations\Console\RunPlatformOperationsDispatchCommand;
use Modules\PlatformSettings\Infrastructure\Persistence\DatabaseBusinessCalendars;
use Modules\PlatformSettings\Infrastructure\Persistence\DatabasePlatformSettings;
use Modules\PlatformSettings\Infrastructure\Persistence\DatabaseTechnicalLogArchiveStore;
use Modules\WorkDefinitions\Contracts\ResolvePublishedRequestFixture;
use Modules\WorkDefinitions\Contracts\ResolvePublishedWorkDefinition;
use Modules\WorkDefinitions\Infrastructure\ResolvePublishedRequestFixtureFromPersistence;
use Modules\WorkDefinitions\Infrastructure\ResolvePublishedWorkDefinitionFromPersistence;
use Modules\Workflow\Contracts\AdvanceWorkflowStep;
use Modules\Workflow\Contracts\ResolveStepAssignee;
use Modules\Workflow\Contracts\ResolveWorkflowSourceAuthorizationFacts;
use Modules\Workflow\Domain\AssignmentRules;
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
        $this->app->bind(PersistAccessDecision::class, DatabasePersistAccessDecision::class);
        $this->app->bind(CountOperationsOfficeMembers::class, DatabaseCountOperationsOfficeMembers::class);
        $this->app->bind(RbacAbacDecideAccess::class, fn ($app): RbacAbacDecideAccess => new RbacAbacDecideAccess(
            $app->make(GetActiveSupervisoryRelationships::class),
            $app->bound(PersistAccessDecision::class)
                ? $app->make(PersistAccessDecision::class)
                : null,
        ));
        $this->app->bind(DecideAccess::class, BootstrapGatedDecideAccess::class);
        $this->app->bind(ResolveAuthorizationSimulationFacts::class, RegisteredAuthorizationSimulationFactsResolver::class);
        $this->app->bind(ResolvePublishedRequestFixture::class, ResolvePublishedRequestFixtureFromPersistence::class);
        $this->app->bind(ResolvePublishedWorkDefinition::class, ResolvePublishedWorkDefinitionFromPersistence::class);
        $this->app->bind(TransactionalOutbox::class, DatabaseTransactionalOutbox::class);
        $this->app->bind(AdvanceWorkflowStep::class, WorkflowStepAdvancer::class);
        $this->app->bind(ResolveStepAssignee::class, AssignmentRules::class);
        $this->app->bind(ResolveWorkflowSourceAuthorizationFacts::class, WorkRecordWorkflowSourceAuthorizationFacts::class);
        $this->app->bind(ResolveQuarantinedImport::class, UnavailableQuarantinedImport::class);
        $this->app->bind(ValidatePersonReference::class, ValidatePersonReferenceFromPersistence::class);
        $this->app->bind(GetActiveSupervisoryRelationships::class, DatabaseGetActiveSupervisoryRelationships::class);
        $this->app->bind(ResolvePersonOrganizationScope::class, DatabaseResolvePersonOrganizationScope::class);
        $this->app->bind(ResolveOrganizationScopeAncestry::class, DatabaseResolveOrganizationScopeAncestry::class);
        $this->app->bind(GetEffectivePlatformSettings::class, DatabasePlatformSettings::class);
        $this->app->bind(ResolveBusinessCalendar::class, fn (): ResolveBusinessCalendar => new DatabaseBusinessCalendars(
            fn (string $scopeType, string $scopeId): ?array => $this->app->make(ResolveOrganizationScopeAncestry::class)->ancestry($scopeType, $scopeId),
        ));
        $this->app->bind(\Modules\PlatformSettings\Features\Calendars\Handler\BusinessCalendarHandler::class, fn (): \Modules\PlatformSettings\Features\Calendars\Handler\BusinessCalendarHandler => new \Modules\PlatformSettings\Features\Calendars\Handler\BusinessCalendarHandler(
            $this->app->make(ResolveBusinessCalendar::class),
        ));
        $this->app->bind(\Modules\PlatformSettings\Features\Maintenance\Handler\MaintenanceWindowHandler::class, fn (): \Modules\PlatformSettings\Features\Maintenance\Handler\MaintenanceWindowHandler => new \Modules\PlatformSettings\Features\Maintenance\Handler\MaintenanceWindowHandler);
        $this->app->bind(\Modules\PlatformSettings\Features\Settings\Handler\PlatformSettingsHandler::class, fn ($app): \Modules\PlatformSettings\Features\Settings\Handler\PlatformSettingsHandler => new \Modules\PlatformSettings\Features\Settings\Handler\PlatformSettingsHandler(
            $app->make(\Modules\PlatformSettings\Infrastructure\Outbox\PlatformSettingsOutbox::class),
        ));
        $this->app->bind(TechnicalLogArchiveStore::class, DatabaseTechnicalLogArchiveStore::class);
        $this->app->bind(TechnicalLogSource::class, fn (): TechnicalLogSource => $this->resolveTechnicalLogSource());
        $this->app->bind(TechnicalLogArchive::class, function (): TechnicalLogArchive {
            try {
                return new ObjectStorageTechnicalLogArchive(
                    \Storage::disk((string) config('platform_operations.logs.archive_disk', 'local')),
                    $this->app->make(TechnicalLogArchiveStore::class),
                );
            } catch (\Throwable) {
                return new class implements TechnicalLogArchive
                {
                    public function archive(\Modules\PlatformSettings\Domain\ArchiveBatch $batch): \Modules\PlatformSettings\Domain\ArchiveManifest
                    {
                        throw new TechnicalLogSourceUnavailable('Technical log archive is not configured.');
                    }

                    public function requestRestore(string $manifestId, string $actorId, string $reason): string
                    {
                        throw new TechnicalLogSourceUnavailable('Technical log archive is not configured.');
                    }
                };
            }
        });
        $this->app->bind(BackupOperationsGateway::class, fn (): BackupOperationsGateway => new CommandBackupOperationsGateway(config('platform_operations')));
        $this->app->bind(\Modules\PlatformSettings\Contracts\PlatformHealthGateway::class, fn (): \Modules\PlatformSettings\Contracts\PlatformHealthGateway => new LaravelPlatformHealthGateway($this->app->make(BackupOperationsGateway::class)));
        $this->app->bind(ValidateTechnicalAlertRecipientCapability::class, CatalogTechnicalAlertRecipientCapabilityValidator::class);
        $this->app->bind(PublishTechnicalAlert::class, AlertPolicyHandler::class);
        $this->app->bind(ResolveTechnicalAlertRecipients::class, DatabaseTechnicalAlertRecipientResolver::class);
        $this->app->bind(ResolvePrincipalContext::class, SessionPrincipalContextResolver::class);
        $this->app->bind(ResolveAccountEntitlement::class, DatabaseResolveAccountEntitlement::class);
        $this->app->bind(ResolveUserForPerson::class, DatabaseResolveUserForPerson::class);
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
        $this->app->bind(DocumentDownloadGrantIssuer::class, S3DocumentDownloadGrantIssuer::class);
        $this->app->bind(DocumentDownloadService::class);
        $this->app->bind(LinkedResourceAuthorizationFacts::class, WorkRecordAuthorizationFacts::class);
        $this->app->bind(SensitiveAccessEventRecorder::class, DatabaseSensitiveAccessEventRecorder::class);
        $this->app->singleton(DocumentUploadPolicy::class, fn (): DocumentUploadPolicy => DocumentUploadPolicy::fromConfig(config('documents')));
        $this->app->singleton(DocumentRetentionPolicy::class, fn (): DocumentRetentionPolicy => DocumentRetentionPolicy::fromConfig(config('documents')));
        $this->app->singleton(ResolveDevelopmentFixturePrincipal::class, function (): ResolveDevelopmentFixturePrincipal {
            if (! $this->developmentFixturesAllowed()) {
                return $this->app->make(SessionPrincipalResolver::class);
            }

            return $this->app->make(DevelopmentFixturePrincipalResolver::class);
        });
        $this->app->when(PlatformSettingsApi::class)
            ->needs(ResolveDevelopmentFixturePrincipal::class)
            ->give(fn ($app) => $app->make(SessionPrincipalResolver::class));
        $this->app->when(PlatformSettingsApi::class)
            ->needs(DecideAccess::class)
            ->give(fn ($app) => $app->make(RbacAbacDecideAccess::class));
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
            DownloadDocumentController::class,
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
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/W2AddOrganizationJobTitlesTable.php'),
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/ZCreateOrganizationUnitsSortOrderTable.php'),
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/ZCreateOrganizationSupervisoryRelationshipTables.php'),
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
            base_path('Modules/Authorization/Infrastructure/Persistence/Migrations/W13AddAuthorizationScopeTypes.php'),
            base_path('Modules/Authorization/Infrastructure/Persistence/Migrations/W13CreateAuthorizationBootstrapTable.php'),
            base_path('Modules/Authorization/Infrastructure/Persistence/Migrations/W13AddExplicitDenyLockVersion.php'),
            base_path('Modules/Documents/Infrastructure/Persistence/Migrations/CreateDocumentsCoreTables.php'),
            base_path('Modules/Documents/Infrastructure/Persistence/Migrations/HardenDocumentUploadSecurityTables.php'),
            base_path('Modules/Documents/Infrastructure/Persistence/Migrations/ZZAddDocumentUploadPurpose.php'),
            base_path('Modules/Documents/Infrastructure/Persistence/Migrations/W18CreateDocumentGovernanceTables.php'),
            base_path('Modules/Documents/Infrastructure/Persistence/Migrations/W19AddDocumentLinkConstraintPolicyKey.php'),
            base_path('Modules/WorkDefinitions/Infrastructure/Persistence/Migrations/CreateDevelopmentWorkTypeFixturesTable.php'),
            base_path('Modules/WorkDefinitions/Infrastructure/Persistence/Migrations/CreateWorkDefinitionTables.php'),
            base_path('Modules/WorkRecords/Infrastructure/Persistence/Migrations/CreateWorkRecordsTable.php'),
            base_path('Modules/WorkRecords/Infrastructure/Persistence/Migrations/W13AddWorkRecordFieldPolicyKey.php'),
            base_path('Modules/WorkRecords/Infrastructure/Outbox/Migrations/CreateOutboxTable.php'),
            base_path('Modules/Workflow/Infrastructure/Persistence/Migrations/CreateWorkflowTables.php'),
            base_path('Modules/Workflow/Infrastructure/Persistence/Migrations/W14AddWorkflowStepAssignee.php'),
            base_path('Modules/Workflow/Infrastructure/Persistence/Migrations/W16CreateWorkflowDecisionsTable.php'),
            base_path('Modules/Workflow/Infrastructure/Persistence/Migrations/W17AddApprovalColumnsToWorkflowVersions.php'),
            base_path('Modules/Tasks/Infrastructure/Persistence/Migrations/CreateTasksTable.php'),
            base_path('Modules/Tasks/Infrastructure/Persistence/Migrations/W13CreateTaskEngagementTables.php'),
            base_path('Modules/Notifications/Infrastructure/Persistence/Migrations/CreateNotificationInboxTable.php'),
            base_path('Modules/Notifications/Infrastructure/Persistence/Migrations/CreateNotificationsTable.php'),
            base_path('Modules/Notifications/Infrastructure/Persistence/Migrations/W18CreateNotificationDeliveryTables.php'),
            base_path('Modules/Notifications/Infrastructure/Persistence/Migrations/W13AddNotificationSourceFacts.php'),
            base_path('Modules/Notifications/Infrastructure/Persistence/Migrations/W20UpgradeTechnicalAlertFanoutSchema.php'),
            base_path('Modules/Search/Infrastructure/Persistence/Migrations/CreateSearchProjectionTables.php'),
            base_path('Modules/Reporting/Infrastructure/Persistence/Migrations/CreateReportingProjectionTables.php'),
            base_path('Modules/PlatformSettings/Infrastructure/Persistence/Migrations/CreatePlatformSettingsTables.php'),
            base_path('Modules/PlatformSettings/Infrastructure/Persistence/Migrations/CreateTechnicalLogArchiveTables.php'),
        ]);
        $this->commands([ExpireTemporaryAssignmentsCommand::class, RunPlatformOperationsDispatchCommand::class]);

        if ($this->authorizationProduction()) {
            $this->assertAuthorizationRuntimeSafe();
        }

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

    private function developmentFixturesAllowed(): bool
    {
        return app()->environment('local') || app()->environment('testing');
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

    private function authorizationProduction(): bool
    {
        return app()->environment('production') && ! app()->runningUnitTests();
    }

    private function documentsRuntimeEnabled(): bool
    {
        return $this->documentsProduction()
            || (app()->environment('testing') && config('documents.runtime.testing_enabled') === true);
    }

    /**
     * Production boot guard: user-facing paths must never resolve the fixture
     * decision engine or the development bearer principal.
     */
    private function assertAuthorizationRuntimeSafe(): void
    {
        /** @var DecideAccess $engine */
        $engine = $this->app->make(DecideAccess::class);
        if (! $engine instanceof BootstrapGatedDecideAccess || ! $engine->usesProductionEngine()) {
            throw new \RuntimeException('Production must bind DecideAccess to the RBAC+ABAC engine.');
        }

        /** @var ResolveDevelopmentFixturePrincipal $principalResolver */
        $principalResolver = $this->app->make(ResolveDevelopmentFixturePrincipal::class);
        if (! $principalResolver instanceof SessionPrincipalResolver) {
            throw new \RuntimeException('Production must resolve user principals from Identity sessions.');
        }
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

    /**
     * The technical-logs capability is DEFERRED. Production never returns
     * the deterministic mock source. The binding resolves to the
     * unavailable sentinel; tests that want the mock must rebind
     * `TechnicalLogSource` explicitly via `$this->app->instance(...)`.
     */
    private function resolveTechnicalLogSource(): TechnicalLogSource
    {
        return new UnavailableTechnicalLogSource;
    }
}
