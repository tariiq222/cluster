<?php

declare(strict_types=1);

namespace Modules\Audit\Tests;

use InvalidArgumentException;
use Modules\Audit\Domain\SensitiveValueRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditRedactionTest extends TestCase
{
    #[DataProvider('sensitiveKeyProvider')]
    public function test_sensitive_keys_are_redacted_at_any_depth_case_insensitively(string $key): void
    {
        $input = [
            'resource_id' => '018f6f7d-0c00-7000-8000-000000000301',
            'nested' => [strtoupper($key) => 'must-not-survive'],
            $key => ['still' => 'must-not-survive'],
        ];
        $original = $input;

        $result = (new SensitiveValueRedactor)->redact($input);

        $this->assertSame('018f6f7d-0c00-7000-8000-000000000301', $result['resource_id']);
        $this->assertSame(SensitiveValueRedactor::REDACTED, $result['nested'][strtoupper($key)]);
        $this->assertSame(SensitiveValueRedactor::REDACTED, $result[$key]);
        $this->assertSame($original, $input);
        $this->assertStringNotContainsString('must-not-survive', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{string}> */
    public static function sensitiveKeyProvider(): iterable
    {
        foreach ([
            'password', 'token', 'authorization', 'cookie', 'secret', 'csrf',
            'credential', 'medical_record_number', 'national_id', 'document_content',
        ] as $key) {
            yield $key => [$key];
        }
    }

    public function test_sensitive_patterns_are_redacted_while_array_shape_and_operational_identifiers_survive(): void
    {
        $input = [
            'correlation_id' => '018f6f7d-0c00-7000-8000-000000000302',
            'list' => [
                'Bearer abc.def+ghi/123=',
                'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjMifQ.signature',
                'national id 1234567890',
                ['session_token' => 'opaque-session-token'],
            ],
        ];

        $result = (new SensitiveValueRedactor)->redact($input);

        $this->assertSame(array_keys($input), array_keys($result));
        $this->assertSame('018f6f7d-0c00-7000-8000-000000000302', $result['correlation_id']);
        $this->assertSame('Bearer [REDACTED]', $result['list'][0]);
        $this->assertSame('[JWT_REDACTED]', $result['list'][1]);
        $this->assertSame('national id [NATIONAL_ID_REDACTED]', $result['list'][2]);
        $this->assertSame(SensitiveValueRedactor::REDACTED, $result['list'][3]['session_token']);
    }

    public function test_unknown_oversized_or_unsupported_context_fails_closed(): void
    {
        $redactor = new SensitiveValueRedactor;

        foreach ([
            ['oversized' => str_repeat('x', 16 * 1024)],
            ['float' => 1.5],
            ['resource' => fopen('php://memory', 'rb')],
            self::nestedContext(7),
        ] as $context) {
            try {
                $redactor->redact($context);
                $this->fail('Expected unsupported context to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            } finally {
                if (isset($context['resource']) && is_resource($context['resource'])) {
                    fclose($context['resource']);
                }
            }
        }
    }

    /**
     * Each bypass shape must be redacted once the key is normalized across
     * `_`, `-`, `.`, and camelCase boundaries. The test exercises every
     * named bypass from the M01 final-review defect list and asserts
     * shape-preserving redaction at any nesting depth.
     */
    #[DataProvider('sensitiveBypassProvider')]
    public function test_sensitive_segment_is_redacted_across_snake_case_camel_case_kebab_and_dotted_forms(string $key, array $context): void
    {
        $input = [
            'resource_id' => '018f6f7d-0c00-7000-8000-000000000303',
            'top' => [
                $key => 'must-not-survive',
            ],
            'deeper' => [
                'middle' => [
                    $key => 'must-not-survive',
                ],
            ],
        ];
        $merged = array_merge($input, $context);

        $result = (new SensitiveValueRedactor)->redact($merged);

        $this->assertSame(SensitiveValueRedactor::REDACTED, $result['top'][$key]);
        $this->assertSame(SensitiveValueRedactor::REDACTED, $result['deeper']['middle'][$key]);
        $this->assertSame('018f6f7d-0c00-7000-8000-000000000303', $result['resource_id']);
        $serialized = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('must-not-survive', $serialized);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function sensitiveBypassProvider(): iterable
    {
        $cases = [
            // snake_case value-trapped in the middle
            'old_password_hash' => 'old_password_hash',
            'access_token_value' => 'access_token_value',
            'current_secret_value' => 'current_secret_value',
            'user_credential_payload' => 'user_credential_payload',
            'session_cookie_blob' => 'session_cookie_blob',
            // camelCase
            'csrfToken' => 'csrfToken',
            'accessToken' => 'accessToken',
            'passwordHash' => 'passwordHash',
            'bearerSecret' => 'bearerSecret',
            'authCookie' => 'authCookie',
            'userCredential' => 'userCredential',
            // kebab-case
            'xsrf-token' => 'xsrf-token',
            'access-token' => 'access-token',
            'old-password' => 'old-password',
            'session-cookie' => 'session-cookie',
            'client-secret' => 'client-secret',
            // dotted / nested namespace
            'headers.Authorization' => 'headers.Authorization',
            'headers.Cookie' => 'headers.Cookie',
            'request.password' => 'request.password',
            'context.csrfToken' => 'context.csrfToken',
            // mixed (camelCase + dot)
            'requestHeaders.Authorization' => 'requestHeaders.Authorization',
            'userSession.csrfToken' => 'userSession.csrfToken',
            // uppercase / kebab case-mixed
            'XSRF-TOKEN' => 'XSRF-TOKEN',
            'PASSWORD-HASH' => 'PASSWORD-HASH',
            'CSRFToken' => 'CSRFToken',
            'Session.Cookie' => 'Session.Cookie',
        ];

        foreach ($cases as $name => $key) {
            yield $name => [$key, []];
        }
    }

    /**
     * Operational identifiers that share no overlap with any sensitive
     * segment must survive verbatim with the same shape. This guards
     * against an over-eager redaction that would mask safe identifiers.
     */
    public function test_safe_identifiers_survive_after_normalization(): void
    {
        $input = [
            'resource_id' => '018f6f7d-0c00-7000-8000-000000000304',
            'user_id' => '018f6f7d-0c00-7000-8000-000000000305',
            'correlation_id' => '018f6f7d-0c00-7000-8000-000000000306',
            'organization_unit_id' => '018f6f7d-0c00-7000-8000-000000000307',
            'subject_type' => 'document',
            'action' => 'document.uploaded',
            'created_at' => '2026-07-27T12:00:00.000Z',
            'links' => [
                'self' => 'https://api.example.com/v1/audit/events/018f6f7d-0c00-7000-8000-000000000308',
            ],
        ];

        $result = (new SensitiveValueRedactor)->redact($input);

        $this->assertSame('018f6f7d-0c00-7000-8000-000000000304', $result['resource_id']);
        $this->assertSame('018f6f7d-0c00-7000-8000-000000000305', $result['user_id']);
        $this->assertSame('018f6f7d-0c00-7000-8000-000000000306', $result['correlation_id']);
        $this->assertSame('018f6f7d-0c00-7000-8000-000000000307', $result['organization_unit_id']);
        $this->assertSame('document', $result['subject_type']);
        $this->assertSame('document.uploaded', $result['action']);
        $this->assertSame(
            'https://api.example.com/v1/audit/events/018f6f7d-0c00-7000-8000-000000000308',
            $result['links']['self'],
        );
    }

    /**
     * The intersection of the new normalization with the previously
     * supported prefix/suffix rule must still hold. A tail prefix match
     * (`password_hash`) and a plain exact match (`password`) and a
     * case-insensitive variant (`PASSWORD`) all redact.
     */
    public function test_legacy_prefix_suffix_and_exact_matches_still_redact(): void
    {
        $input = [
            'password' => 'a',
            'PASSWORD' => 'b',
            'password_hash' => 'c',
            'user_password' => 'd',
            '_password' => 'e',
            'password_' => 'f',
        ];

        $result = (new SensitiveValueRedactor)->redact($input);

        foreach ($input as $key => $value) {
            $this->assertSame(
                SensitiveValueRedactor::REDACTED,
                $result[$key],
                'Legacy prefix/suffix/exact match for "password" must still redact for key '.$key,
            );
        }
    }

    /** @return array<string, mixed> */
    private static function nestedContext(int $depth): array
    {
        $value = ['leaf' => true];
        for ($i = 0; $i < $depth; $i++) {
            $value = ['level_'.$i => $value];
        }

        return $value;
    }
}
