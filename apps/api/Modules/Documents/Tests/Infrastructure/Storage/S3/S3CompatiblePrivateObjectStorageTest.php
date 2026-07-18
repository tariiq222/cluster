<?php

namespace Modules\Documents\Tests\Infrastructure\Storage\S3;

use Modules\Documents\Application\QuarantineObjectReference;
use Modules\Documents\Application\QuarantineUploadRequest;
use Modules\Documents\Application\RetryableStorageException;
use Modules\Documents\Application\StoredObjectProperties;
use Modules\Documents\Application\VerifiedQuarantineObject;
use Modules\Documents\Infrastructure\Storage\S3\DeterministicObjectKeyResolver;
use Modules\Documents\Infrastructure\Storage\S3\S3CompatibleConfiguration;
use Modules\Documents\Infrastructure\Storage\S3\S3CompatiblePrivateObjectStorage;
use Modules\Documents\Infrastructure\Storage\S3\SigV4RequestSigner;
use PHPUnit\Framework\TestCase;

final class S3CompatiblePrivateObjectStorageTest extends TestCase
{
    public function test_signed_upload_intent_binds_exact_content_conditions_and_allowlisted_endpoint(): void
    {
        [$storage, $executor] = $this->makeStorage();
        $intent = $storage->issueQuarantineUpload(new QuarantineUploadRequest(
            '018f6f7d-0c00-7000-8000-000000000501',
            '018f6f7d-0c00-7000-8000-000000000502',
            '018f6f7d-0c00-7000-8000-000000000502.blob',
            'application/pdf',
            1024,
            str_repeat('a', 64),
            new \DateTimeImmutable('2026-07-18T12:36:00+00:00'),
        ));

        $this->assertSame('PUT', $intent->method);
        $this->assertSame('018f6f7d-0c00-7000-8000-000000000501', $intent->id);
        $this->assertStringStartsWith('https://', $intent->url);
        $this->assertSame('127.0.0.1', parse_url($intent->url, PHP_URL_HOST));
        $this->assertSame(9000, parse_url($intent->url, PHP_URL_PORT));
        $this->assertStringContainsString('/documents-quarantine/', parse_url($intent->url, PHP_URL_PATH) ?: '');
        $this->assertSame('1024', $intent->requiredHeaders['content-length']);
        $this->assertSame('application/pdf', $intent->requiredHeaders['content-type']);
        $this->assertSame(str_repeat('a', 64), $intent->requiredHeaders['x-amz-content-sha256']);
        $this->assertSame(base64_encode(hex2bin(str_repeat('a', 64))), $intent->requiredHeaders['x-amz-checksum-sha256']);
        $this->assertSame('*', $intent->requiredHeaders['if-none-match']);
        $this->assertSame('private', $intent->requiredHeaders['x-amz-acl']);
        $this->assertSame('aws:kms', $intent->requiredHeaders['x-amz-server-side-encryption']);
        $this->assertSame('quarantine-kms-key', $intent->requiredHeaders['x-amz-server-side-encryption-aws-kms-key-id']);
        $this->assertSame('session-token', $intent->requiredHeaders['x-amz-security-token']);
        $this->assertArrayHasKey('x-amz-date', $intent->requiredHeaders);
        $this->assertArrayHasKey('Authorization', $intent->requiredHeaders);
        $this->assertArrayNotHasKey('host', $intent->requiredHeaders);
        preg_match('/SignedHeaders=([^,]+)/', $intent->requiredHeaders['Authorization'], $matches);
        foreach (explode(';', $matches[1] ?? '') as $signedHeader) {
            if ($signedHeader !== 'host') {
                $this->assertArrayHasKey($signedHeader, $intent->requiredHeaders);
            }
        }
        $this->assertEmpty($executor->requests, 'Pre-signed URLs must not perform outbound traffic.');
    }

    public function test_inspect_uses_checksum_enabled_signed_head(): void
    {
        [$storage, $executor] = $this->makeStorage();
        $etag = '"d41d8cd98f00b204e9800998ecf8427e"';
        $sha = str_repeat('b', 64);
        $executor->responses = [
            [
                'status' => 200,
                'headers' => [
                    'etag' => $etag,
                    'content-length' => '1024',
                    'content-type' => 'application/pdf',
                    'x-amz-checksum-sha256' => base64_encode(hex2bin($sha)),
                ],
                'body' => '',
            ],
        ];
        $properties = $storage->inspectQuarantineObject(new QuarantineObjectReference('018f6f7d-0c00-7000-8000-000000000502'));
        $this->assertSame(1024, $properties->sizeBytes);
        $this->assertSame('application/pdf', $properties->detectedMimeType);
        $this->assertSame($sha, $properties->sha256);
        $this->assertSame($etag, $properties->etag);
        $this->assertCount(1, $executor->requests);
        $this->assertSame('HEAD', $executor->requests[0]['method']);
        $this->assertSame('ENABLED', $executor->requests[0]['headers']['x-amz-checksum-mode']);
        $this->assertStringContainsString('AWS4-HMAC-SHA256', $executor->requests[0]['headers']['Authorization'] ?? '');
    }

    public function test_signed_upload_intent_omits_kms_headers_when_no_quarantine_key_is_configured(): void
    {
        [$storage] = $this->makeStorage(quarantineKmsKeyId: null);

        $intent = $storage->issueQuarantineUpload(new QuarantineUploadRequest(
            '018f6f7d-0c00-7000-8000-000000000501',
            '018f6f7d-0c00-7000-8000-000000000502',
            '018f6f7d-0c00-7000-8000-000000000502.blob',
            'text/csv',
            1024,
            str_repeat('a', 64),
            new \DateTimeImmutable('2026-07-18T12:36:00+00:00'),
        ));

        $this->assertArrayNotHasKey('x-amz-server-side-encryption', $intent->requiredHeaders);
        $this->assertArrayNotHasKey('x-amz-server-side-encryption-aws-kms-key-id', $intent->requiredHeaders);
    }

    public function test_inspect_returns_404_when_object_missing(): void
    {
        [$storage, $executor] = $this->makeStorage();
        $executor->responses = [
            ['status' => 404, 'headers' => [], 'body' => ''],
        ];
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('quarantine_object_unavailable');
        $storage->inspectQuarantineObject(new QuarantineObjectReference('018f6f7d-0c00-7000-8000-000000000502'));
    }

    public function test_inspect_returns_retryable_when_storage_5xx(): void
    {
        [$storage, $executor] = $this->makeStorage();
        $executor->responses = [
            ['status' => 503, 'headers' => [], 'body' => ''],
        ];
        $this->expectException(RetryableStorageException::class);
        $storage->inspectQuarantineObject(new QuarantineObjectReference('018f6f7d-0c00-7000-8000-000000000502'));
    }

    public function test_promotion_uses_copy_with_if_match_etag_and_rechecks_etag(): void
    {
        [$storage, $executor] = $this->makeStorage();
        $etag = '"c81e728d9d4c2f636f067f89cc14862c"';
        $executor->responses = [
            ['status' => 200, 'headers' => ['etag' => $etag, 'x-amz-version-id' => 'v1'], 'body' => ''],
        ];
        $verified = new VerifiedQuarantineObject(
            new QuarantineObjectReference('018f6f7d-0c00-7000-8000-000000000502'),
            new StoredObjectProperties(str_repeat('c', 64), 1024, 'application/pdf', $etag, 'v1'),
        );
        $properties = $storage->promoteVerifiedObject($verified);
        $this->assertSame($etag, $properties->etag);
        $this->assertSame('v1', $properties->generation);
        $this->assertCount(1, $executor->requests);
        $copy = $executor->requests[0];
        $this->assertSame('PUT', $copy['method']);
        $this->assertStringContainsString('/documents-available/', parse_url($copy['url'], PHP_URL_PATH) ?: '');
        $this->assertSame('/documents-quarantine/018f6f7d-0c00-7000-8000-000000000502.blob', $copy['headers']['x-amz-copy-source'] ?? '');
        $this->assertSame($etag, $copy['headers']['x-amz-copy-source-if-match'] ?? '');
    }

    public function test_promotion_refuses_when_returned_etag_does_not_match(): void
    {
        [$storage, $executor] = $this->makeStorage();
        $executor->responses = [
            ['status' => 200, 'headers' => ['etag' => '"different-etag"', 'x-amz-version-id' => 'v1'], 'body' => ''],
        ];
        $verified = new VerifiedQuarantineObject(
            new QuarantineObjectReference('018f6f7d-0c00-7000-8000-000000000502'),
            new StoredObjectProperties(str_repeat('c', 64), 1024, 'application/pdf', '"original-etag"', 'v1'),
        );
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('document_promotion_etag_mismatch');
        $storage->promoteVerifiedObject($verified);
    }

    /**
     * @return array{0: S3CompatiblePrivateObjectStorage, 1: FakeS3RequestExecutor, 2: SigV4RequestSigner}
     */
    private function makeStorage(?string $quarantineKmsKeyId = 'quarantine-kms-key'): array
    {
        $config = S3CompatibleConfiguration::forTesting(
            region: 'us-east-1',
            endpoint: 'https://127.0.0.1:9000',
            usePathStyle: true,
            quarantineBucket: 'documents-quarantine',
            availableBucket: 'documents-available',
            accessKeyId: 'AKIDEXAMPLE',
            secretAccessKey: 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            sessionToken: 'session-token',
            quarantineKmsKeyId: $quarantineKmsKeyId,
        );
        $signer = new SigV4RequestSigner('us-east-1', 'AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY');
        $resolver = new DeterministicObjectKeyResolver;
        $executor = new FakeS3RequestExecutor;
        $storage = new S3CompatiblePrivateObjectStorage($config, $signer, $executor, $resolver);

        return [$storage, $executor, $signer];
    }
}
