<?php

namespace Modules\Documents\Infrastructure\Storage\S3;

/**
 * Streaming boundary for the S3-compatible object store. Unlike
 * {@see S3RequestExecutor::execute()}, this contract never materialises the
 * response body in PHP memory; the caller pulls chunks through the returned
 * {@see S3ResponseStream} so a 200 MiB quarantine object can be streamed
 * straight into the ClamAV transport.
 */
interface StreamingS3RequestExecutor
{
    /**
     * @param  array<string, string>  $signedHeaders
     *
     * @throws \Modules\Documents\Application\RetryableStorageException on transport-level failure.
     */
    public function executeStream(string $method, string $url, array $signedHeaders, string $body): S3ResponseStream;
}
