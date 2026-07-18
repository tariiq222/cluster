<?php

namespace Modules\Documents\Infrastructure\Storage\S3;

/**
 * Single outbound request contract for an S3-compatible object storage. The
 * adapter wraps SigV4 signing on top of this contract so production code and
 * tests can both exercise the signed-request flow without coupling to a
 * specific HTTP client.
 */
interface S3RequestExecutor
{
    /**
     * @param  array<string, string>  $signedHeaders
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function execute(string $method, string $url, array $signedHeaders, string $body): array;
}
