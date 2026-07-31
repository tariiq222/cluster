<?php

namespace Modules\Documents\Tests\Infrastructure\Storage\S3;

use Modules\Documents\Infrastructure\Storage\S3\S3ResponseStream;

/**
 * Test double that returns a canned {@see S3ResponseStream} for each
 * {@see StreamingS3RequestExecutor::executeStream()} call. The fake records
 * every request the adapter issues so production tests can assert the right
 * signed GET was sent.
 */
final class FakeStreamingS3RequestExecutor implements \Modules\Documents\Infrastructure\Storage\S3\StreamingS3RequestExecutor
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    public array $requests = [];

    /** @var list<S3ResponseStream> */
    public array $responses = [];

    public ?\Throwable $throwWith = null;

    public function executeStream(string $method, string $url, array $signedHeaders, string $body): S3ResponseStream
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $signedHeaders,
            'body' => $body,
        ];
        if ($this->throwWith !== null) {
            throw $this->throwWith;
        }
        if ($this->responses === []) {
            return new InMemoryS3ResponseStream(200, [], []);
        }

        return array_shift($this->responses);
    }
}
