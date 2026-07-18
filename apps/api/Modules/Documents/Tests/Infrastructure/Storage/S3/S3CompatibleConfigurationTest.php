<?php

namespace Modules\Documents\Tests\Infrastructure\Storage\S3;

use Modules\Documents\Infrastructure\Storage\S3\S3CompatibleConfiguration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class S3CompatibleConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        foreach ([
            'DOCUMENTS_S3_REGION',
            'DOCUMENTS_S3_ENDPOINT',
            'DOCUMENTS_S3_USE_PATH_STYLE',
            'DOCUMENTS_S3_QUARANTINE_BUCKET',
            'DOCUMENTS_S3_AVAILABLE_BUCKET',
            'DOCUMENTS_S3_ACCESS_KEY_ID',
            'DOCUMENTS_S3_SECRET_ACCESS_KEY',
        ] as $name) {
            unset($_ENV[$name]);
        }
    }

    public function test_returns_test_defaults_when_environment_is_empty(): void
    {
        $config = S3CompatibleConfiguration::fromEnvironment(testing: true);
        $this->assertSame('test-documents_s3_region', $config->region);
        $this->assertSame('test-documents_s3_quarantine_bucket', $config->quarantineBucket);
        $this->assertSame('test-documents_s3_available_bucket', $config->availableBucket);
        $this->assertTrue($config->usePathStyle);
    }

    public function test_uses_amazon_default_endpoint_when_endpoint_is_empty(): void
    {
        $config = S3CompatibleConfiguration::forTesting(
            region: 'eu-west-1',
            endpoint: '',
            usePathStyle: false,
            quarantineBucket: 'q',
            availableBucket: 'a',
            accessKeyId: 'k',
            secretAccessKey: 's',
        );
        $this->assertSame('s3.eu-west-1.amazonaws.com', $config->host());
        $this->assertSame('https', $config->scheme());
    }

    public function test_rejects_matching_buckets_outside_testing(): void
    {
        $_ENV['DOCUMENTS_S3_REGION'] = 'us-east-1';
        $_ENV['DOCUMENTS_S3_QUARANTINE_BUCKET'] = 'shared';
        $_ENV['DOCUMENTS_S3_AVAILABLE_BUCKET'] = 'shared';
        $_ENV['DOCUMENTS_S3_ACCESS_KEY_ID'] = 'k';
        $_ENV['DOCUMENTS_S3_SECRET_ACCESS_KEY'] = 's';
        $_ENV['DOCUMENTS_UPLOAD_INTENT_TTL_SECONDS'] = '120';
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('buckets must differ');
            S3CompatibleConfiguration::fromEnvironment(testing: false);
        } finally {
            unset(
                $_ENV['DOCUMENTS_S3_REGION'],
                $_ENV['DOCUMENTS_S3_QUARANTINE_BUCKET'],
                $_ENV['DOCUMENTS_S3_AVAILABLE_BUCKET'],
                $_ENV['DOCUMENTS_S3_ACCESS_KEY_ID'],
                $_ENV['DOCUMENTS_S3_SECRET_ACCESS_KEY'],
                $_ENV['DOCUMENTS_UPLOAD_INTENT_TTL_SECONDS'],
            );
        }
    }

    public function test_includes_port_in_host_when_endpoint_specifies_one(): void
    {
        $config = S3CompatibleConfiguration::forTesting(
            region: 'us-east-1',
            endpoint: 'https://minio.local:9000',
            usePathStyle: true,
            quarantineBucket: 'q',
            availableBucket: 'a',
            accessKeyId: 'k',
            secretAccessKey: 's',
        );
        $this->assertSame('minio.local:9000', $config->hostWithPort());
        $this->assertSame('minio.local', $config->host());
    }
}
