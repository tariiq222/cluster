<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Outbox;

use InvalidArgumentException;
use Shared\Infrastructure\Outbox\OutboxEventType;

/**
 * Maps the short `IdentityOutbox::insertSecurityEvent()` suffix
 * (e.g. `session_created`) to its matching `OutboxEventType` enum case.
 *
 * The outbox relay only recognises CloudEvents whose `type` matches a
 * case in {@see OutboxEventType}; a typo or rename in the producer
 * previously slipped through as the literal
 * `com.cluster.identity.<suffix>.v1` and failed silently downstream.
 * Routing every security-event suffix through this registry makes the
 * boundary explicit: an unknown suffix raises
 * `InvalidArgumentException` at the outbox helper instead of producing
 * a string the relay does not know about.
 *
 * The registry covers every suffix the Identity module emits through
 * IdentityOutbox::insertSecurityEvent(), including the two legacy
 * cases (account_login_locked, authentication_failed) that share the
 * security-event shape. The main OutboxEventType enum still owns the
 * canonical event-type string; this class is the Identity-side
 * adapter that turns the suffix contract into a fully-qualified type.
 */
final class IdentitySecurityEventRegistry
{
    /**
     * Suffix → enum case. Suffixes are the exact strings producers pass
     * to {@see IdentityOutbox::insertSecurityEvent()}. The enum case
     * carries the CloudEvents `type` value the outbox relay dispatches
     * on, so this table is the single point where suffix and
     * fully-qualified event type are reconciled.
     *
     * @var array<string, OutboxEventType>
     */
    private const SUFFIX_TO_CASE = [
        'account_activated' => OutboxEventType::IdentityAccountActivated,
        'account_login_locked' => OutboxEventType::IdentityAccountLoginLocked,
        'activation_token_issued' => OutboxEventType::IdentityActivationTokenIssued,
        'authentication_failed' => OutboxEventType::IdentityAuthenticationFailed,
        'authentication_succeeded' => OutboxEventType::IdentityAuthenticationSucceeded,
        'credential_created' => OutboxEventType::IdentityCredentialCreated,
        'password_changed' => OutboxEventType::IdentityPasswordChanged,
        'session_created' => OutboxEventType::IdentitySessionCreated,
        'session_revoked' => OutboxEventType::IdentitySessionRevoked,
        'sessions_revoked' => OutboxEventType::IdentitySessionsRevoked,
        'totp_enrollment_started' => OutboxEventType::IdentityTotpEnrollmentStarted,
        'totp_enabled' => OutboxEventType::IdentityTotpEnabled,
    ];

    /**
     * Resolve a producer-supplied security-event suffix to its matching
     * `OutboxEventType` case.
     *
     * @throws InvalidArgumentException when `$type` is not a registered
     *         security-event suffix. The error is raised here rather
     *         than letting `OutboxEventType::from()` raise a `ValueError`
     *         on the assembled string so the failure is labelled with
     *         the suffix the producer actually passed.
     */
    public function resolve(string $type): OutboxEventType
    {
        if (! isset(self::SUFFIX_TO_CASE[$type])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown Identity security-event suffix "%s"; expected one of: %s.',
                $type,
                implode(', ', array_keys(self::SUFFIX_TO_CASE)),
            ));
        }

        return self::SUFFIX_TO_CASE[$type];
    }

    /**
     * @return list<string>
     */
    public function suffixes(): array
    {
        return array_keys(self::SUFFIX_TO_CASE);
    }
}
