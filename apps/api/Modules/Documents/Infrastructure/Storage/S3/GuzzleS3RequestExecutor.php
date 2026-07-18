<?php

namespace Modules\Documents\Infrastructure\Storage\S3;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request;
use Modules\Documents\Application\RetryableStorageException;
use Throwable;

/**
 * Guzzle-backed production executor for {@see S3RequestExecutor}. Maps network
 * errors to {@see RetryableStorageException} so callers can fail the upload
 * pipeline cleanly and let the worker retry the inspection step. 4xx responses
 * are returned verbatim so the storage adapter can decide whether a missing
 * object is a genuine 404 or a transient failure.
 */
final class GuzzleS3RequestExecutor implements S3RequestExecutor
{
    public function __construct(
        private readonly Client $client,
        private readonly int $connectTimeoutSeconds = 5,
        private readonly int $readTimeoutSeconds = 30,
    ) {}

    /** @param array<string, string> $signedHeaders */
    public function execute(string $method, string $url, array $signedHeaders, string $body): array
    {
        $request = new Request($method, $url, $signedHeaders, $body);
        try {
            $response = $this->client->send($request, [
                'http_errors' => false,
                'connect_timeout' => $this->connectTimeoutSeconds,
                'read_timeout' => $this->readTimeoutSeconds,
                'allow_redirects' => false,
            ]);
        } catch (RequestException $exception) {
            throw new RetryableStorageException('documents_s3_network_error', previous: $exception);
        } catch (TransferException $exception) {
            throw new RetryableStorageException('documents_s3_transfer_error', previous: $exception);
        } catch (Throwable $exception) {
            throw new RetryableStorageException('documents_s3_unexpected_error', previous: $exception);
        }
        $status = $response->getStatusCode();
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        return [
            'status' => $status,
            'headers' => $headers,
            'body' => (string) $response->getBody(),
        ];
    }
}
