<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DocumentsTestingRuntimeConfigTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    public function test_testing_runtime_requires_the_literal_true_value(): void
    {
        $this->setEnvironmentVariable('APP_ENV', 'testing');
        $this->setEnvironmentVariable('DOCUMENTS_TEST_RUNTIME_ENABLED', 'true');
        $this->setEnvironmentVariable('DOCUMENTS_UPLOAD_ENDPOINT_ALLOWLIST', 'minio.testing');

        $config = require dirname(__DIR__, 2).'/config/documents.php';

        $this->assertTrue($config['runtime']['testing_enabled']);
        $this->assertSame(['minio.testing'], $config['storage']['upload_endpoint_allowlist']);
    }

    public function test_testing_runtime_rejects_non_literal_true_or_false_values(): void
    {
        $this->setEnvironmentVariable('APP_ENV', 'testing');
        $this->setEnvironmentVariable('DOCUMENTS_TEST_RUNTIME_ENABLED', '1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DOCUMENTS_TEST_RUNTIME_ENABLED must be exactly true or false.');

        require dirname(__DIR__, 2).'/config/documents.php';
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

    private function setEnvironmentVariable(string $name, string $value): void
    {
        if (! array_key_exists($name, $this->originalEnvironment)) {
            $this->originalEnvironment[$name] = getenv($name);
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv("{$name}={$value}");
    }
}
