<?php

namespace Modules\Documents\Infrastructure\Storage\S3;

use Modules\Documents\Application\RetryableStorageException;

/**
 * Concrete {@see QuarantineObjectByteSource} that reads the quarantine object
 * back from the configured S3-compatible bucket. It uses a range GET so the
 * transfer size scales with the object instead of forcing a full download,
 * but it always reads the whole object so clamd can scan every byte.
 */
final class S3QuarantineObjectByteSource implements QuarantineObjectByteSource
{
    private const SIGV4_PAYLOAD_HASH = 'UNSIGNED-PAYLOAD';

    public function __construct(
        private readonly S3CompatibleConfiguration $configuration,
        private readonly SigV4RequestSigner $signer,
        private readonly S3RequestExecutor $executor,
        private readonly ObjectKeyResolver $keyResolver,
    ) {}

    public function fetchBytes(string $storageObjectId): string
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
        $response = $this->executor->execute('GET', $url, $headers, '');
        if ($response['status'] !== 200) {
            throw new RetryableStorageException('documents_s3_fetch_failed');
        }

        return $response['body'];
    }
}
