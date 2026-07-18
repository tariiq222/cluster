<?php

namespace Modules\Documents\Tests\Infrastructure\Security;

use Modules\Documents\Infrastructure\Security\ClamAvConfiguration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ClamAvConfigurationTest extends TestCase
{
    public function test_defaults_apply_when_environment_is_empty_in_testing(): void
    {
        $config = ClamAvConfiguration::fromEnvironment(testing: true);
        $this->assertSame('disabled', $config->transport);
        $this->assertSame(3310, $config->port);
        $this->assertSame(65536, $config->chunkBytes);
        $this->assertSame('clamav-test', $config->engineName);
    }

    public function test_rejects_unknown_transport(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Documents ClamAV transport');
        ClamAvConfiguration::fromEnvironment(testing: true);
        $_ENV['DOCUMENTS_CLAMAV_TRANSPORT'] = 'bogus';
        try {
            ClamAvConfiguration::fromEnvironment(testing: false);
        } finally {
            unset($_ENV['DOCUMENTS_CLAMAV_TRANSPORT']);
        }
    }

    public function test_requires_host_for_tcp_transport_outside_testing(): void
    {
        $_ENV['DOCUMENTS_CLAMAV_TRANSPORT'] = 'tcp';
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('DOCUMENTS_CLAMAV_HOST');
            ClamAvConfiguration::fromEnvironment(testing: false);
        } finally {
            unset($_ENV['DOCUMENTS_CLAMAV_TRANSPORT']);
        }
    }

    public function test_requires_socket_for_unix_transport_outside_testing(): void
    {
        $_ENV['DOCUMENTS_CLAMAV_TRANSPORT'] = 'unix';
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('DOCUMENTS_CLAMAV_SOCKET');
            ClamAvConfiguration::fromEnvironment(testing: false);
        } finally {
            unset($_ENV['DOCUMENTS_CLAMAV_TRANSPORT']);
        }
    }

    public function test_requires_engine_metadata_outside_testing(): void
    {
        $_ENV['DOCUMENTS_CLAMAV_TRANSPORT'] = 'tcp';
        $_ENV['DOCUMENTS_CLAMAV_HOST'] = '127.0.0.1';
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('DOCUMENTS_CLAMAV_ENGINE_NAME');
            ClamAvConfiguration::fromEnvironment(testing: false);
        } finally {
            unset($_ENV['DOCUMENTS_CLAMAV_TRANSPORT'], $_ENV['DOCUMENTS_CLAMAV_HOST']);
        }
    }
}
