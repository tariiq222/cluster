<?php

namespace Modules\Documents\Infrastructure\Storage\S3;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request;
use Modules\Documents\Application\RetryableStorageException;
use Throwable;

/**
 * Guzzle-backed production executor for {@see StreamingS3RequestExecutor}.
 * It uses Guzzle's {@code stream=true} option so the response body is read
 * lazily by the caller instead of being cast to a string up-front. Network
 * errors map to {@see RetryableStorageException} so callers can fail the
 * upload pipeline cleanly and let the worker retry the inspection step.
 */
final class GuzzleStreamingS3RequestExecutor implements StreamingS3RequestExecutor
{
    public function __construct(
        private readonly Client $client,
        private readonly int $connectTimeoutSeconds = 5,
        private readonly int $readTimeoutSeconds = 30,
    ) {}

    /** @param array<string, string> $signedHeaders */
    public function executeStream(string $method, string $url, array $signedHeaders, string $body): S3ResponseStream
    {
        $request = new Request($method, $url, $signedHeaders, $body);
        try {
            $response = $this->client->send($request, [
                'http_errors' => false,
                'connect_timeout' => $this->connectTimeoutSeconds,
                'read_timeout' => $this->readTimeoutSeconds,
                'allow_redirects' => false,
                'stream' => true,
            ]);
        } catch (RequestException $exception) {
            throw new RetryableStorageException('documents_s3_network_error', previous: $exception);
        } catch (TransferException $exception) {
            throw new RetryableStorageException('documents_s3_transfer_error', previous: $exception);
        } catch (Throwable $exception) {
            throw new RetryableStorageException('documents_s3_unexpected_error', previous: $exception);
        }

        return new GuzzleS3ResponseStream($response);
    }
}
