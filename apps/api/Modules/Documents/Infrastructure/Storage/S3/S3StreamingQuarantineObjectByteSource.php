<?php

namespace Modules\Documents\Infrastructure\Storage\S3;

use Modules\Documents\Infrastructure\Storage\QuarantineByteChunkReader;
use Modules\Documents\Infrastructure\Storage\StreamingQuarantineObjectByteSource;

/**
 * Concrete {@see StreamingQuarantineObjectByteSource} that pulls the verified
 * quarantine object from the configured S3-compatible bucket via a streaming
 * {@see StreamingS3RequestExecutor}. The implementation never holds the full
 * body in PHP memory — chunks are fetched lazily as the
 * {@see QuarantineByteChunkReader} pulls them, then forwarded straight to the
 * ClamAV transport.
 *
 * <p>The source uses a plain range-free GET so S3-compatible backends that
 * do not support range requests (or partial object fetches) still work. The
 * stream does the chunking on the client side so the wire protocol stays
 * identical to the previous materialising implementation.
 */
final class S3StreamingQuarantineObjectByteSource implements StreamingQuarantineObjectByteSource
{
    private const SIGV4_PAYLOAD_HASH = 'UNSIGNED-PAYLOAD';

    public function __construct(
        private readonly S3CompatibleConfiguration $configuration,
        private readonly SigV4RequestSigner $signer,
        private readonly StreamingS3RequestExecutor $executor,
        private readonly ObjectKeyResolver $keyResolver,
    ) {}

    public function openChunkReader(string $storageObjectId, int $chunkBytes): QuarantineByteChunkReader
    {
        $objectKey = $this->keyResolver->quarantineKeyById($storageObjectId);
        $host = $this->configuration->hostWithPort();
        $uri = $this->configuration->usePathStyle
            ? '/'.$this->configuration->quarantineBucket.'/'.rawurlencode($objectKey)
            : '/'.rawurlencode($objectKey);
        $headers = [
            'Host' => $host,
            'x-amz-content-sha256' => self::SIGV4_PAYLOAD_HASH,
        ];
        if ($this->configuration->sessionToken !== null && $this->configuration->sessionToken !== '') {
            $headers['X-Amz-Security-Token'] = $this->configuration->sessionToken;
        }
        $headers = $this->signer->sign('GET', $host, $uri, '', $headers, self::SIGV4_PAYLOAD_HASH);
        $url = sprintf('%s://%s%s', $this->configuration->scheme(), $host, $uri);
        $stream = $this->executor->executeStream('GET', $url, $headers, '');

        return new S3QuarantineByteChunkReader($stream, $chunkBytes);
    }
}
