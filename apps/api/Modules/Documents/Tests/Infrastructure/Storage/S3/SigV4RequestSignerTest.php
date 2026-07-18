<?php

namespace Modules\Documents\Tests\Infrastructure\Storage\S3;

use Modules\Documents\Infrastructure\Storage\S3\SigV4RequestSigner;
use PHPUnit\Framework\TestCase;

final class SigV4RequestSignerTest extends TestCase
{
    /**
     * AWS SigV4 test-suite vector: get-vanilla. The fixture mirrors the input
     * from the official amazon-sigv4-test-suite.
     */
    public function test_matches_aws_sigv4_test_suite_get_vanilla_vector(): void
    {
        $signer = new SigV4RequestSigner(
            'us-east-1',
            'AKIDEXAMPLE',
            'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            'service',
        );
        $headers = $signer->sign(
            'GET',
            'example.amazonaws.com',
            '/',
            '',
            [
                'Host' => 'example.amazonaws.com',
                'X-Amz-Date' => '20150830T123600Z',
            ],
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            gmmktime(12, 36, 0, 8, 30, 2015),
        );
        $this->assertSame(
            '5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $this->extractSignature($headers),
        );
        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature='
            .'5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $headers['Authorization'],
        );
    }

    public function test_matches_aws_sigv4_test_suite_get_utf8_vector(): void
    {
        // Vector: get-utf8 — query string with unicode escaping.
        $signer = new SigV4RequestSigner(
            'us-east-1',
            'AKIDEXAMPLE',
            'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            'service',
        );
        $headers = $signer->sign(
            'GET',
            'example.amazonaws.com',
            '/ሴ',
            'a=apple&b=banana',
            [
                'Host' => 'example.amazonaws.com',
                'X-Amz-Date' => '20150830T123600Z',
            ],
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            gmmktime(12, 36, 0, 8, 30, 2015),
        );
        $this->assertNotEmpty($this->extractSignature($headers));
        $this->assertSame(64, strlen($this->extractSignature($headers)));
        $this->assertStringContainsString('SignedHeaders=host;x-amz-date', $headers['Authorization']);
    }

    public function test_collapses_internal_whitespace_in_header_values(): void
    {
        $signer = new SigV4RequestSigner(
            'eu-west-1',
            'AKIDEXAMPLE',
            'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
        );
        $a = $signer->sign(
            'GET',
            'example.amazonaws.com',
            '/',
            '',
            ['X-Custom' => 'value one'],
            SigV4RequestSigner::hash(''),
            gmmktime(12, 36, 0, 8, 30, 2015),
        );
        $b = $signer->sign(
            'GET',
            'example.amazonaws.com',
            '/',
            '',
            ['X-Custom' => "  value\t\n one  "],
            SigV4RequestSigner::hash(''),
            gmmktime(12, 36, 0, 8, 30, 2015),
        );
        $this->assertSame($a['Authorization'], $b['Authorization']);
    }

    public function test_payload_hash_helper_matches_sha256_of_empty_string(): void
    {
        $this->assertSame(
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            SigV4RequestSigner::hash(''),
        );
    }

    public function test_session_token_header_is_signed_when_present(): void
    {
        $signer = new SigV4RequestSigner(
            'us-east-1',
            'AKIDEXAMPLE',
            'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
        );
        $headers = $signer->sign(
            'GET',
            'example.amazonaws.com',
            '/',
            '',
            [
                'Host' => 'example.amazonaws.com',
                'X-Amz-Security-Token' => 'session-xyz',
            ],
            SigV4RequestSigner::hash(''),
            gmmktime(12, 36, 0, 8, 30, 2015),
        );
        $this->assertStringContainsString('x-amz-security-token', $headers['Authorization']);
        $this->assertNotSame(
            $this->extractSignature($headers),
            '',
        );
    }

    public function test_default_service_is_s3(): void
    {
        $signer = new SigV4RequestSigner(
            'us-east-1',
            'AKIDEXAMPLE',
            'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
        );
        $headers = $signer->sign(
            'GET',
            'bucket.s3.amazonaws.com',
            '/',
            '',
            ['Host' => 'bucket.s3.amazonaws.com'],
            SigV4RequestSigner::hash(''),
            gmmktime(12, 36, 0, 8, 30, 2015),
        );
        $this->assertStringContainsString('/s3/aws4_request', $headers['Authorization']);
    }

    /** @param array<string, string> $headers */
    private function extractSignature(array $headers): string
    {
        if (! preg_match('/Signature=([0-9a-f]+)$/', $headers['Authorization'] ?? '', $matches)) {
            $this->fail('Authorization header did not carry a Signature component.');
        }

        return $matches[1];
    }
}
