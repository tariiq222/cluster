<?php

namespace Tests\Feature;

use Modules\Documents\Contracts\MalwareScanner;
use Modules\Documents\Contracts\PrivateObjectStorage;
use Modules\Documents\Infrastructure\Security\ClamAvConfiguration;
use Modules\Documents\Infrastructure\Security\ClamAvMalwareScanner;
use Modules\Documents\Infrastructure\Security\UnavailableMalwareScanner;
use Modules\Documents\Infrastructure\Storage\S3\S3CompatibleConfiguration;
use Modules\Documents\Infrastructure\Storage\S3\S3CompatiblePrivateObjectStorage;
use Modules\Documents\Infrastructure\Storage\UnavailablePrivateObjectStorage;
use Tests\TestCase;

final class DocumentsRuntimeProviderTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    public function test_testing_defaults_to_unavailable_documents_adapters(): void
    {
        $this->assertFalse(config('documents.runtime.testing_enabled'));
        $this->assertInstanceOf(UnavailablePrivateObjectStorage::class, $this->app->make(PrivateObjectStorage::class));
        $this->assertInstanceOf(UnavailableMalwareScanner::class, $this->app->make(MalwareScanner::class));
    }

    public function test_explicit_testing_runtime_flag_binds_real_documents_adapters(): void
    {
        $this->enableTestingRuntime();
        $this->refreshApplication();

        $this->assertTrue(config('documents.runtime.testing_enabled'));
        $this->assertSame(['minio.testing'], config('documents.storage.upload_endpoint_allowlist'));
        $this->assertInstanceOf(S3CompatiblePrivateObjectStorage::class, $this->app->make(PrivateObjectStorage::class));
        $this->assertInstanceOf(ClamAvMalwareScanner::class, $this->app->make(MalwareScanner::class));

        $storage = $this->app->make(S3CompatibleConfiguration::class);
        $scanner = $this->app->make(ClamAvConfiguration::class);
        $this->assertSame('http://minio.testing:9000', $storage->endpoint);
        $this->assertSame('quarantine-testing', $storage->quarantineBucket);
        $this->assertSame('available-testing', $storage->availableBucket);
        $this->assertSame('tcp', $scanner->transport);
        $this->assertSame('clamav.testing', $scanner->host);
    }

    public function test_local_environment_cannot_enable_real_documents_adapters(): void
    {
        $this->enableTestingRuntime();
        $this->refreshApplication();
        $this->app->detectEnvironment(static fn (): string => 'local');

        $this->assertFalse(app()->environment('testing'));
        $this->assertInstanceOf(UnavailablePrivateObjectStorage::class, $this->app->make(PrivateObjectStorage::class));
        $this->assertInstanceOf(UnavailableMalwareScanner::class, $this->app->make(MalwareScanner::class));
    }

    public function test_production_runtime_defaults_to_enabled_when_flag_is_absent(): void
    {
        $this->unsetEnvironmentVariable('DOCUMENTS_PRODUCTION_RUNTIME_ENABLED');
        $this->setEnvironmentVariable('APP_ENV', 'production');

        /** @var array{runtime: array{production_enabled: bool}} $configuration */
        $configuration = require base_path('config/documents.php');

        $this->assertTrue($configuration['runtime']['production_enabled']);
    }

    public function test_explicitly_disabled_production_runtime_binds_unavailable_adapters(): void
    {
        $this->setEnvironmentVariable('APP_ENV', 'production');
        $this->setEnvironmentVariable('DOCUMENTS_PRODUCTION_RUNTIME_ENABLED', 'false');
        $this->refreshApplication();

        $this->assertFalse(config('documents.runtime.production_enabled'));
        $this->assertInstanceOf(UnavailablePrivateObjectStorage::class, $this->app->make(PrivateObjectStorage::class));
        $this->assertInstanceOf(UnavailableMalwareScanner::class, $this->app->make(MalwareScanner::class));
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            if ($value === false) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);
            } else {
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
                putenv("{$name}={$value}");
            }
        }

        parent::tearDown();
    }

    private function enableTestingRuntime(): void
    {
        foreach ([
            'DOCUMENTS_TEST_RUNTIME_ENABLED' => 'true',
            'DOCUMENTS_UPLOAD_ENDPOINT_ALLOWLIST' => 'minio.testing',
            'DOCUMENTS_S3_REGION' => 'us-east-1',
            'DOCUMENTS_S3_ENDPOINT' => 'http://minio.testing:9000',
            'DOCUMENTS_S3_USE_PATH_STYLE' => 'true',
            'DOCUMENTS_S3_QUARANTINE_BUCKET' => 'quarantine-testing',
            'DOCUMENTS_S3_AVAILABLE_BUCKET' => 'available-testing',
            'DOCUMENTS_S3_ACCESS_KEY_ID' => 'minio-testing-key',
            'DOCUMENTS_S3_SECRET_ACCESS_KEY' => 'minio-testing-secret',
            'DOCUMENTS_UPLOAD_INTENT_TTL_SECONDS' => '300',
            'DOCUMENTS_CLAMAV_TRANSPORT' => 'tcp',
            'DOCUMENTS_CLAMAV_HOST' => 'clamav.testing',
            'DOCUMENTS_CLAMAV_PORT' => '3310',
            'DOCUMENTS_CLAMAV_ENGINE_NAME' => 'clamav-testing',
            'DOCUMENTS_CLAMAV_SIGNATURE_VERSION' => 'testing-signatures-v1',
        ] as $name => $value) {
            $this->setEnvironmentVariable($name, $value);
        }
    }

    private function setEnvironmentVariable(string $name, string $value): void
    {
        if (! array_key_exists($name, $this->originalEnvironment)) {
            $this->originalEnvironment[$name] = getenv($name);
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv("{$name}={$value}");
    }

    private function unsetEnvironmentVariable(string $name): void
    {
        if (! array_key_exists($name, $this->originalEnvironment)) {
            $this->originalEnvironment[$name] = getenv($name);
        }

        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
    }
}
