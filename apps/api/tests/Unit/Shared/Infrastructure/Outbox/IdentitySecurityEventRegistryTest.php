<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Outbox;

use InvalidArgumentException;
use Modules\Identity\Infrastructure\Outbox\IdentitySecurityEventRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shared\Infrastructure\Outbox\OutboxEventType;

#[CoversClass(IdentitySecurityEventRegistry::class)]
final class IdentitySecurityEventRegistryTest extends TestCase
{
    /**
     * Every suffix `IdentityOutbox::insertSecurityEvent()` accepts must
     * resolve to a registered case — a missing case means the producer
     * would silently emit a string the outbox relay does not know about.
     *
     * @return iterable<string, array{string, OutboxEventType}>
     */
    public static function registeredSuffixProvider(): iterable
    {
        yield 'account_activated' => [
            'account_activated',
            OutboxEventType::IdentityAccountActivated,
        ];

        yield 'account_login_locked' => [
            'account_login_locked',
            OutboxEventType::IdentityAccountLoginLocked,
        ];

        yield 'activation_token_issued' => [
            'activation_token_issued',
            OutboxEventType::IdentityActivationTokenIssued,
        ];

        yield 'authentication_failed' => [
            'authentication_failed',
            OutboxEventType::IdentityAuthenticationFailed,
        ];

        yield 'authentication_succeeded' => [
            'authentication_succeeded',
            OutboxEventType::IdentityAuthenticationSucceeded,
        ];

        yield 'credential_created' => [
            'credential_created',
            OutboxEventType::IdentityCredentialCreated,
        ];

        yield 'password_changed' => [
            'password_changed',
            OutboxEventType::IdentityPasswordChanged,
        ];

        yield 'session_created' => [
            'session_created',
            OutboxEventType::IdentitySessionCreated,
        ];

        yield 'session_revoked' => [
            'session_revoked',
            OutboxEventType::IdentitySessionRevoked,
        ];

        yield 'sessions_revoked' => [
            'sessions_revoked',
            OutboxEventType::IdentitySessionsRevoked,
        ];

        yield 'totp_enrollment_started' => [
            'totp_enrollment_started',
            OutboxEventType::IdentityTotpEnrollmentStarted,
        ];

        yield 'totp_enabled' => [
            'totp_enabled',
            OutboxEventType::IdentityTotpEnabled,
        ];
    }

    #[DataProvider('registeredSuffixProvider')]
    public function test_every_registered_suffix_resolves_to_a_matching_enum_case(
        string $suffix,
        OutboxEventType $expected,
    ): void {
        $registry = new IdentitySecurityEventRegistry;

        $this->assertSame(
            $expected,
            $registry->resolve($suffix),
            "Suffix '{$suffix}' must resolve to OutboxEventType::{$expected->name}.",
        );
    }

    /**
     * Every resolved case must carry a CloudEvents `type` value under
     * the `com.cluster.identity.*.v1` namespace — the relay dispatches
     * on this value, so a misaligned namespace would silently drop the
     * event downstream.
     */
    #[DataProvider('registeredSuffixProvider')]
    public function test_every_registered_case_carries_an_identity_namespace_type(
        string $suffix,
        OutboxEventType $expected,
    ): void {
        $registry = new IdentitySecurityEventRegistry;

        $resolved = $registry->resolve($suffix);

        $this->assertMatchesRegularExpression(
            '/^com\.cluster\.identity\.[a-z][a-z0-9_]*\.v\d+$/',
            $resolved->value,
            "OutboxEventType::{$resolved->name} value must live under com.cluster.identity.*.v1.",
        );
    }

    public function test_unknown_suffix_throws_invalid_argument_exception(): void
    {
        $registry = new IdentitySecurityEventRegistry;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown Identity security-event suffix "definitely_not_registered"');

        $registry->resolve('definitely_not_registered');
    }

    public function test_empty_string_is_rejected(): void
    {
        $registry = new IdentitySecurityEventRegistry;

        $this->expectException(InvalidArgumentException::class);

        $registry->resolve('');
    }
}
