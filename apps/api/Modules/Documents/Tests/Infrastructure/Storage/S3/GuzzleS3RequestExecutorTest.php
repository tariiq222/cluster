<?php

namespace Modules\Documents\Tests\Infrastructure\Storage\S3;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Modules\Documents\Application\RetryableStorageException;
use Modules\Documents\Infrastructure\Storage\S3\GuzzleS3RequestExecutor;
use PHPUnit\Framework\TestCase;

final class GuzzleS3RequestExecutorTest extends TestCase
{
    public function test_returns_response_status_and_lowercased_headers(): void
    {
        $mock = new MockHandler([
            new Response(200, ['ETag' => '"abc"', 'Content-Length' => '0'], ''),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $executor = new GuzzleS3RequestExecutor($client, 5, 10);
        $response = $executor->execute('PUT', 'https://example.test/bucket/key', ['Authorization' => 'AWS4-HMAC-SHA256 ...'], '');
        $this->assertSame(200, $response['status']);
        $this->assertSame('"abc"', $response['headers']['etag']);
        $this->assertSame('0', $response['headers']['content-length']);
        $this->assertSame('', $response['body']);
    }

    public function test_returns_response_verbatim_on_4xx(): void
    {
        $mock = new MockHandler([
            new Response(403, [], 'AccessDenied'),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $executor = new GuzzleS3RequestExecutor($client, 5, 10);
        $response = $executor->execute('PUT', 'https://example.test/bucket/key', [], '');
        $this->assertSame(403, $response['status']);
        $this->assertSame('AccessDenied', $response['body']);
    }

    public function test_translates_guzzle_network_errors_to_retryable_storage_exception(): void
    {
        $mock = new MockHandler([
            new ConnectException('connection refused', new Request('PUT', 'https://example.test/')),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $executor = new GuzzleS3RequestExecutor($client, 5, 10);
        $this->expectException(RetryableStorageException::class);
        $this->expectExceptionMessage('documents_s3_');
        $executor->execute('PUT', 'https://example.test/bucket/key', [], '');
    }
}
