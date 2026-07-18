<?php

namespace Modules\Documents\Infrastructure\Storage\S3;

use DateTimeImmutable;
use DomainException;
use Modules\Documents\Application\QuarantineObjectReference;
use Modules\Documents\Application\QuarantineUploadRequest;
use Modules\Documents\Application\RetryableStorageException;
use Modules\Documents\Application\SignedUploadIntent;
use Modules\Documents\Application\StoredObjectProperties;
use Modules\Documents\Application\VerifiedQuarantineObject;
use Modules\Documents\Contracts\PrivateObjectStorage;

/**
 * S3-compatible production adapter for the {@see PrivateObjectStorage} boundary.
 *
 * <p>Three operations are exposed:
 * <ul>
 *   <li>{@see issueQuarantineUpload()} returns a one-shot pre-signed PUT URL
 *       bound to the exact {@code Content-Length}, {@code Content-Type},
 *       {@code x-amz-checksum-sha256}, and {@code If-None-Match: *} condition.
 *       The URL embeds the upload intent id so the application server never
 *       reveals the object key to clients.</li>
 *   <li>{@see inspectQuarantineObject()} issues a checksum-enabled HEAD to
 *       read the ETag, Content-Length, and SHA-256 that were bound to the
 *       direct upload.</li>
 *   <li>{@see promoteVerifiedObject()} copies the verified source generation
 *       (bound by {@code x-amz-copy-source-if-match}) to the available bucket
 *       and re-reads the new generation.</li>
 * </ul>
 *
 * <p>Object keys are constructed server-side as {@code <storage_object_id>.blob}
 * inside the configured bucket prefix; callers never see them. Network errors
 * are translated to {@see RetryableStorageException} so the upload handler can
 * defer completion until the storage recovers.
 */
final class S3CompatiblePrivateObjectStorage implements PrivateObjectStorage
{
    private const COPY_SOURCE_IF_MATCH_HEADER = 'x-amz-copy-source-if-match';

    private const CHECKSUM_SHA256_HEADER = 'x-amz-checksum-sha256';

    public function __construct(
        private readonly S3CompatibleConfiguration $configuration,
        private readonly SigV4RequestSigner $signer,
        private readonly S3RequestExecutor $executor,
        /** Maps storage object id (UUIDv7) to the opaque object key inside the bucket. */
        private readonly ObjectKeyResolver $keyResolver,
    ) {}

    public function issueQuarantineUpload(QuarantineUploadRequest $request): SignedUploadIntent
    {
        $objectKey = $this->keyResolver->quarantineKey($request->objectKey());
        $host = $this->configuration->hostWithPort();
        $canonicalUri = $this->canonicalUri($objectKey, $this->configuration->quarantineBucket);
        $queryString = http_build_query([
            'X-Amz-Expires' => $this->configuration->uploadIntentTtlSeconds,
        ], '', '&', PHP_QUERY_RFC3986);
        $now = time();
        $headers = [
            'Host' => $this->configuration->hostWithPort(),
            'Content-Length' => (string) $request->expectedSizeBytes,
            'Content-Type' => $request->declaredMimeType,
            'X-Amz-Content-Sha256' => $request->expectedSha256,
            self::CHECKSUM_SHA256_HEADER => base64_encode(hex2bin($request->expectedSha256)),
            'If-None-Match' => '*',
            'X-Amz-Acl' => 'private',
        ];
        if ($this->configuration->quarantineKmsKeyId !== null && $this->configuration->quarantineKmsKeyId !== '') {
            $headers['X-Amz-Server-Side-Encryption'] = 'aws:kms';
            $headers['X-Amz-Server-Side-Encryption-Aws-Kms-Key-Id'] = $this->configuration->quarantineKmsKeyId;
        }
        if ($this->configuration->sessionToken !== null && $this->configuration->sessionToken !== '') {
            $headers['X-Amz-Security-Token'] = $this->configuration->sessionToken;
        }
        $headers = $this->signer->sign(
            'PUT',
            $host,
            $canonicalUri,
            $queryString,
            $headers,
            $request->expectedSha256,
            $now,
        );
        // The client must replay every signed request header. Host is the sole
        // exception: browsers derive it from the upload URL and do not permit
        // callers to set it explicitly.
        $requiredHeaders = $headers;
        unset($requiredHeaders['host']);
        $url = sprintf(
            '%s://%s%s?%s',
            $this->configuration->scheme(),
            $this->configuration->hostWithPort(),
            $canonicalUri,
            $queryString,
        );

        return new SignedUploadIntent(
            $request->uploadIntentId,
            $url,
            'PUT',
            (new DateTimeImmutable('@'.$now))->modify('+'.$this->configuration->uploadIntentTtlSeconds.' seconds'),
            $requiredHeaders,
        );
    }

    public function inspectQuarantineObject(QuarantineObjectReference $reference): StoredObjectProperties
    {
        $objectKey = $this->keyResolver->quarantineKeyById($reference->storageObjectId);
        $head = $this->headObject($objectKey, $this->configuration->quarantineBucket);
        $etag = $this->etagFromHeaders($head['headers']);
        $sizeBytes = $this->sizeFromHeaders($head['headers']);
        $sha256 = $this->checksumFromHeaders($head['headers']);

        return new StoredObjectProperties(
            $sha256,
            $sizeBytes,
            $this->inferMimeFromHeaders($head['headers']),
            $etag,
            $this->generationFromHeaders($head['headers'], $etag),
        );
    }

    public function promoteVerifiedObject(VerifiedQuarantineObject $object): StoredObjectProperties
    {
        $sourceKey = $this->keyResolver->quarantineKeyById($object->reference->storageObjectId);
        $destKey = $this->keyResolver->availableKeyById($object->reference->storageObjectId);
        $host = $this->configuration->hostWithPort();
        $uri = $this->canonicalUri($destKey, $this->configuration->availableBucket);
        $headers = [
            'Host' => $host,
            'Content-Length' => '0',
            'x-amz-copy-source' => '/'.$this->configuration->quarantineBucket.'/'.$sourceKey,
            self::COPY_SOURCE_IF_MATCH_HEADER => $object->properties->etag,
            'x-amz-metadata-directive' => 'COPY',
        ];
        if ($this->configuration->availableKmsKeyId !== null && $this->configuration->availableKmsKeyId !== '') {
            $headers['X-Amz-Server-Side-Encryption'] = 'aws:kms';
            $headers['X-Amz-Server-Side-Encryption-Aws-Kms-Key-Id'] = $this->configuration->availableKmsKeyId;
        }
        if ($this->configuration->sessionToken !== null && $this->configuration->sessionToken !== '') {
            $headers['X-Amz-Security-Token'] = $this->configuration->sessionToken;
        }
        $headers = $this->signer->sign('PUT', $host, $uri, '', $headers, SigV4RequestSigner::hash(''));
        $url = sprintf('%s://%s%s', $this->configuration->scheme(), $host, $uri);
        $response = $this->executor->execute('PUT', $url, $headers, '');
        if ($response['status'] !== 200) {
            throw $this->unexpectedResponse('documents_s3_copy_failed', $response['status']);
        }
        $etag = $this->etagFromHeaders($response['headers']);
        if ($etag === '' || ! hash_equals($object->properties->etag, $etag)) {
            throw new DomainException('document_promotion_etag_mismatch');
        }

        return new StoredObjectProperties(
            $object->properties->sha256,
            $object->properties->sizeBytes,
            $object->properties->detectedMimeType,
            $etag,
            $this->generationFromHeaders($response['headers'], $etag),
        );
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function headObject(string $objectKey, string $bucket): array
    {
        $host = $this->configuration->hostWithPort();
        $uri = $this->canonicalUri($objectKey, $bucket);
        $headers = [
            'Host' => $host,
            'x-amz-checksum-mode' => 'ENABLED',
        ];
        if ($this->configuration->sessionToken !== null && $this->configuration->sessionToken !== '') {
            $headers['X-Amz-Security-Token'] = $this->configuration->sessionToken;
        }
        $headers = $this->signer->sign('HEAD', $host, $uri, '', $headers, SigV4RequestSigner::hash(''));
        $url = sprintf('%s://%s%s', $this->configuration->scheme(), $host, $uri);
        $response = $this->executor->execute('HEAD', $url, $headers, '');
        if ($response['status'] === 404) {
            throw new DomainException('quarantine_object_unavailable');
        }
        if ($response['status'] >= 500) {
            throw new RetryableStorageException('documents_s3_head_5xx');
        }
        if ($response['status'] !== 200) {
            throw new DomainException('quarantine_object_unavailable');
        }

        return $response;
    }

    /** @param array<string, string> $headers */
    private function checksumFromHeaders(array $headers): string
    {
        $checksum = $headers['x-amz-checksum-sha256'] ?? '';
        $decoded = base64_decode($checksum, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new DomainException('document_quarantine_checksum_missing');
        }

        return bin2hex($decoded);
    }

    private function canonicalUri(string $objectKey, string $bucket): string
    {
        $encodedKey = $this->encodeObjectKey($objectKey);
        if ($this->configuration->usePathStyle) {
            return '/'.$bucket.'/'.$encodedKey;
        }

        return '/'.$encodedKey;
    }

    private function encodeObjectKey(string $objectKey): string
    {
        $segments = explode('/', $objectKey);
        $encoded = array_map(static fn (string $segment): string => rawurlencode($segment), $segments);

        return implode('/', $encoded);
    }

    /** @param array<string, string> $headers */
    private function etagFromHeaders(array $headers): string
    {
        $etag = $headers['etag'] ?? '';
        $etag = trim($etag);
        if ($etag === '') {
            throw new DomainException('document_quarantine_etag_missing');
        }

        return $etag;
    }

    /** @param array<string, string> $headers */
    private function sizeFromHeaders(array $headers): int
    {
        $raw = $headers['content-length'] ?? '';
        if (! ctype_digit($raw)) {
            throw new DomainException('document_quarantine_size_missing');
        }
        $size = (int) $raw;
        if ($size < 1) {
            throw new DomainException('document_quarantine_size_invalid');
        }

        return $size;
    }

    /** @param array<string, string> $headers */
    private function inferMimeFromHeaders(array $headers): string
    {
        $mime = strtolower((string) ($headers['content-type'] ?? ''));
        if (preg_match('/\A[a-z0-9][a-z0-9.+-]*\/[a-z0-9][a-z0-9.+-]*\z/', $mime) !== 1) {
            throw new DomainException('document_quarantine_mime_invalid');
        }

        return $mime;
    }

    /** @param array<string, string> $headers */
    private function generationFromHeaders(array $headers, string $etag): string
    {
        $generation = (string) ($headers['x-amz-version-id'] ?? '');
        if ($generation === '') {
            $generation = $etag;
        }

        return $generation;
    }

    private function unexpectedResponse(string $code, int $status): DomainException|RetryableStorageException
    {
        if ($status >= 500) {
            return new RetryableStorageException($code);
        }

        return new DomainException('quarantine_object_unavailable');
    }
}
