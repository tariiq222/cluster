<?php

namespace Modules\Documents\Infrastructure\Storage\S3;

use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Documents\Application\DocumentDownloadGrant;
use Modules\Documents\Contracts\DocumentDownloadGrantIssuer;

/** Issues a short-lived SigV4 GET URL for an available document object. */
final class S3DocumentDownloadGrantIssuer implements DocumentDownloadGrantIssuer
{
    public function __construct(
        private readonly S3CompatibleConfiguration $configuration,
        private readonly ObjectKeyResolver $keyResolver,
    ) {}

    public function issue(string $documentId, string $versionId, string $principalId): DocumentDownloadGrant
    {
        $storageObjectId = DB::table('document_versions as v')
            ->join('documents as d', 'd.id', '=', 'v.document_id')
            ->join('document_storage_objects as o', 'o.id', '=', 'v.storage_object_id')
            ->where('d.public_id', $documentId)
            ->where('v.public_id', $versionId)
            ->value('o.id');
        if (! is_string($storageObjectId) || $storageObjectId === '') {
            throw new DomainException('document_version_not_found');
        }

        $ttl = $this->configuration->uploadIntentTtlSeconds;
        $expiresAt = (new DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("+{$ttl} seconds");
        $url = $this->presignedUrl($this->keyResolver->availableKeyById($storageObjectId), $ttl);

        return new DocumentDownloadGrant($documentId, $versionId, $url, $expiresAt, Str::uuid7()->toString());
    }

    private function presignedUrl(string $objectKey, int $ttl): string
    {
        $host = $this->configuration->hostWithPort();
        $encoded = implode('/', array_map('rawurlencode', explode('/', $objectKey)));
        $uri = $this->configuration->usePathStyle
            ? '/'.$this->configuration->availableBucket.'/'.$encoded
            : '/'.$encoded;
        $timestamp = time();
        $amzDate = gmdate('Ymd\THis\Z', $timestamp);
        $date = gmdate('Ymd', $timestamp);
        $scope = $date.'/'.$this->configuration->region.'/s3/aws4_request';
        $query = [
            'X-Amz-Algorithm' => SigV4RequestSigner::ALGORITHM,
            'X-Amz-Credential' => $this->configuration->accessKeyId.'/'.$scope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) $ttl,
            'X-Amz-SignedHeaders' => 'host',
        ];
        if ($this->configuration->sessionToken !== null && $this->configuration->sessionToken !== '') {
            $query['X-Amz-Security-Token'] = $this->configuration->sessionToken;
        }
        ksort($query);
        $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $canonicalRequest = "GET\n{$uri}\n{$canonicalQuery}\nhost:{$host}\n\nhost\n".SigV4RequestSigner::hash('');
        $stringToSign = SigV4RequestSigner::ALGORITHM."\n{$amzDate}\n{$scope}\n".SigV4RequestSigner::hash($canonicalRequest);
        $kDate = hash_hmac('sha256', $date, 'AWS4'.$this->configuration->secretAccessKey, true);
        $kRegion = hash_hmac('sha256', $this->configuration->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $kService, true);
        $query['X-Amz-Signature'] = hash_hmac('sha256', $stringToSign, $signingKey);

        return $this->configuration->scheme().'://'.$host.$uri.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
