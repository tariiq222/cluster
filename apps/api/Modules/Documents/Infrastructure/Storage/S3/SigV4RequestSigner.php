<?php

namespace Modules\Documents\Infrastructure\Storage\S3;

/**
 * Pure PHP AWS Signature V4 signer for S3-compatible object storage. The
 * signer only produces the headers required for a single signed request; it
 * does not stream payloads. Callers are responsible for sending the canonical
 * payload hash that {@see hash()} returns.
 *
 * The implementation follows the canonicalisation rules in the AWS SigV4
 * specification and is S3-correct for PUT/HEAD/GET/COPY against AWS S3, MinIO,
 * Cloudflare R2, Backblaze B2, and Wasabi when the caller supplies the right
 * endpoint shape (virtual-host or path-style) and region.
 */
final class SigV4RequestSigner
{
    public const ALGORITHM = 'AWS4-HMAC-SHA256';

    public const SERVICE_S3 = 's3';

    public function __construct(
        private readonly string $region,
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        private readonly string $service = self::SERVICE_S3,
    ) {}

    /** @return array<string, string> */
    public function sign(
        string $method,
        string $host,
        string $canonicalUri,
        string $canonicalQueryString,
        array $headers,
        string $payloadHash,
        ?int $nowTimestamp = null,
    ): array {
        $nowTimestamp ??= time();
        $amzDate = gmdate('Ymd\THis\Z', $nowTimestamp);
        $dateStamp = gmdate('Ymd', $nowTimestamp);

        $headers['X-Amz-Date'] = $amzDate;
        $headers = $this->canonicaliseHeaders($headers);
        $signedHeaders = $this->signedHeadersList($headers);
        $canonicalRequest = $this->canonicalRequest(
            strtoupper($method),
            $canonicalUri,
            $canonicalQueryString,
            $headers,
            $signedHeaders,
            $payloadHash,
        );
        $scope = sprintf('%s/%s/%s/aws4_request', $dateStamp, $this->region, $this->service);
        $stringToSign = $this->stringToSign($amzDate, $scope, $canonicalRequest);
        $signingKey = $this->deriveSigningKey($dateStamp);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $headers['Authorization'] = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $this->accessKeyId,
            $scope,
            $signedHeaders,
            $signature,
        );

        return $headers;
    }

    public static function hash(string $payload): string
    {
        return hash('sha256', $payload);
    }

    /** @param array<string, string> $headers */
    private function canonicaliseHeaders(array $headers): array
    {
        $normalised = [];
        foreach ($headers as $name => $value) {
            $key = strtolower((string) $name);
            if ($key === 'authorization') {
                continue;
            }
            $normalised[$key] = preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';
        }
        ksort($normalised);

        return $normalised;
    }

    /** @param array<string, string> $headers */
    private function signedHeadersList(array $headers): string
    {
        return implode(';', array_keys($headers));
    }

    /** @param array<string, string> $headers */
    private function canonicalRequest(
        string $method,
        string $canonicalUri,
        string $canonicalQueryString,
        array $headers,
        string $signedHeaders,
        string $payloadHash,
    ): string {
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name.':'.$value."\n";
        }

        return implode("\n", [
            $method,
            $canonicalUri,
            $canonicalQueryString,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);
    }

    private function stringToSign(string $amzDate, string $scope, string $canonicalRequest): string
    {
        return implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);
    }

    private function deriveSigningKey(string $dateStamp): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4'.$this->secretAccessKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $this->service, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
