<?php

namespace Modules\Documents\Tests\Infrastructure\Storage\S3;

use Modules\Documents\Application\RetryableStorageException;
use Modules\Documents\Infrastructure\Storage\S3\DeterministicObjectKeyResolver;
use Modules\Documents\Infrastructure\Storage\S3\S3CompatibleConfiguration;
use Modules\Documents\Infrastructure\Storage\S3\S3StreamingQuarantineObjectByteSource;
use Modules\Documents\Infrastructure\Storage\S3\SigV4RequestSigner;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see S3StreamingQuarantineObjectByteSource} that wire a fake
 * streaming executor and assert:
 *
 * <ul>
 *   <li>The source issues a signed GET for the quarantine object.</li>
 *   <li>The chunk reader surfaces chunks in order without holding the full body.</li>
 *   <li>The reader signals EOF by returning {@code null} once the stream is exhausted.</li>
 *   <li>The reader closes the underlying S3 response stream exactly once.</li>
 *   <li>Non-200 responses throw {@see RetryableStorageException} on first read.</li>
 * </ul>
 */
final class S3StreamingQuarantineObjectByteSourceTest extends TestCase
{
    public function test_open_chunk_reader_issues_signed_get_for_quarantine_object(): void
    {
        $executor = new FakeStreamingS3RequestExecutor;
        $executor->responses[] = new InMemoryS3ResponseStream(200, [], ['payload']);
        $source = $this->source($executor);

        $reader = $source->openChunkReader('018f6f7d-0c00-7000-8000-000000000502', 8192);
        $reader->close();

        $this->assertCount(1, $executor->requests);
        $request = $executor->requests[0];
        $this->assertSame('GET', $request['method']);
        $this->assertStringContainsString('quarantine-testing', $request['url']);
        $this->assertStringContainsString('018f6f7d-0c00-7000-8000-000000000502', $request['url']);
        $this->assertArrayHasKey('Authorization', $request['headers']);
    }

    public function test_reader_returns_chunks_in_order_until_eof(): void
    {
        $executor = new FakeStreamingS3RequestExecutor;
        $executor->responses[] = new InMemoryS3ResponseStream(200, [], ['first', 'second', 'third']);
        $source = $this->source($executor);
        $reader = $source->openChunkReader('018f6f7d-0c00-7000-8000-000000000502', 4096);

        $this->assertSame('first', $reader->readChunk());
        $this->assertSame('second', $reader->readChunk());
        $this->assertSame('third', $reader->readChunk());
        $this->assertNull($reader->readChunk());
    }

    public function test_reader_closes_underlying_stream_after_eof(): void
    {
        $executor = new FakeStreamingS3RequestExecutor;
        $stream = new InMemoryS3ResponseStream(200, [], ['only']);
        $executor->responses[] = $stream;
        $source = $this->source($executor);
        $reader = $source->openChunkReader('018f6f7d-0c00-7000-8000-000000000502', 4096);

        $this->assertSame('only', $reader->readChunk());
        $this->assertNull($reader->readChunk());
        $this->assertSame(1, $stream->closeCount);
    }

    public function test_close_is_idempotent(): void
    {
        $executor = new FakeStreamingS3RequestExecutor;
        $stream = new InMemoryS3ResponseStream(200, [], ['only']);
        $executor->responses[] = $stream;
        $source = $this->source($executor);
        $reader = $source->openChunkReader('018f6f7d-0c00-7000-8000-000000000502', 4096);
        $reader->close();
        $reader->close();
        $this->assertSame(1, $stream->closeCount);
    }

    public function test_non_200_response_throws_retryable_storage_exception_on_first_read(): void
    {
        $executor = new FakeStreamingS3RequestExecutor;
        $executor->responses[] = new InMemoryS3ResponseStream(404, [], []);
        $source = $this->source($executor);
        $reader = $source->openChunkReader('018f6f7d-0c00-7000-8000-000000000502', 4096);

        $this->expectException(RetryableStorageException::class);
        $reader->readChunk();
    }

    public function test_reader_does_not_materialise_full_payload(): void
    {
        $executor = new FakeStreamingS3RequestExecutor;
        $stream = new InMemoryS3ResponseStream(200, [], [str_repeat('A', 65536), str_repeat('B', 65536)]);
        $executor->responses[] = $stream;
        $source = $this->source($executor);
        $reader = $source->openChunkReader('018f6f7d-0c00-7000-8000-000000000502', 65536);

        $first = $reader->readChunk();
        $this->assertNotNull($first);
        $this->assertSame(65536, strlen($first));
        $this->assertSame(65536, $stream->peakChunkBytes);
        $reader->close();
    }

    public function test_reader_closes_underlying_stream_when_close_called_directly(): void
    {
        $executor = new FakeStreamingS3RequestExecutor;
        $stream = new InMemoryS3ResponseStream(200, [], ['only']);
        $executor->responses[] = $stream;
        $source = $this->source($executor);
        $reader = $source->openChunkReader('018f6f7d-0c00-7000-8000-000000000502', 4096);

        $reader->close();
        $this->assertSame(1, $stream->closeCount);
    }

    private function source(FakeStreamingS3RequestExecutor $executor): S3StreamingQuarantineObjectByteSource
    {
        return new S3StreamingQuarantineObjectByteSource(
            S3CompatibleConfiguration::forTesting(
                region: 'us-east-1',
                endpoint: 'http://minio.testing:9000',
                usePathStyle: true,
                quarantineBucket: 'quarantine-testing',
                availableBucket: 'available-testing',
                accessKeyId: 'minio-testing-key',
                secretAccessKey: 'minio-testing-secret',
            ),
            new SigV4RequestSigner('us-east-1', 'minio-testing-key', 'minio-testing-secret'),
            $executor,
            new DeterministicObjectKeyResolver,
        );
    }
}
