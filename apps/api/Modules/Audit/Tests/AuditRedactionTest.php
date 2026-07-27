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

        $result = (new SensitiveValueRedactor())->redact($input);

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

        $result = (new SensitiveValueRedactor())->redact($input);

        $this->assertSame(array_keys($input), array_keys($result));
        $this->assertSame('018f6f7d-0c00-7000-8000-000000000302', $result['correlation_id']);
        $this->assertSame('Bearer [REDACTED]', $result['list'][0]);
        $this->assertSame('[JWT_REDACTED]', $result['list'][1]);
        $this->assertSame('national id [NATIONAL_ID_REDACTED]', $result['list'][2]);
        $this->assertSame(SensitiveValueRedactor::REDACTED, $result['list'][3]['session_token']);
    }

    public function test_unknown_oversized_or_unsupported_context_fails_closed(): void
    {
        $redactor = new SensitiveValueRedactor();

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
