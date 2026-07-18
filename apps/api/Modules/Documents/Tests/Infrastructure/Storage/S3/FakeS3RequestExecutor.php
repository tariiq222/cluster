<?php

namespace Modules\Documents\Tests\Infrastructure\Storage\S3;

use Modules\Documents\Infrastructure\Storage\S3\S3RequestExecutor;

/**
 * In-process {@see S3RequestExecutor} that records each call and replays
 * canned responses. Tests use this fake to assert that the production adapter
 * issues the expected signed requests and that responses map to the right
 * result types.
 */
final class FakeS3RequestExecutor implements S3RequestExecutor
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    public array $requests = [];

    /** @var list<array{status: int, headers: array<string, string>, body: string}> */
    public array $responses = [];

    public function execute(string $method, string $url, array $signedHeaders, string $body): array
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $signedHeaders,
            'body' => $body,
        ];
        if ($this->responses === []) {
            return ['status' => 200, 'headers' => [], 'body' => ''];
        }

        return array_shift($this->responses);
    }
}
