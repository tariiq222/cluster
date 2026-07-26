<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Outbox;

use Illuminate\Support\Str;
use Shared\Contracts\TransactionalOutboxEnvelope;
use Shared\Infrastructure\Outbox\OutboxEventType;

/**
 * Module-owned outbox façade for Identity producers.
 *
 * Owns the producer-side CloudEvent assembly (UUIDv7 generation,
 * security-event payload allow-list, time-stamping) and forwards the
 * verbatim envelope to the Shared
 * {@see TransactionalOutboxEnvelope} implementation. Direct
 * `DB::table('outbox_events')` access is intentionally absent: the
 * architecture scanner
 * (`Tests\Architecture\ModuleBoundariesTest`) flags any producer
 * module that bypasses the Shared contract.
 *
 * `OutboxEventType::from` is invoked here so a producer-side typo on
 * `type` fails fast (ValueError on an unknown case) before the
 * envelope reaches the Shared adapter.
 */
final class IdentityOutbox
{
    public function __construct(
        private readonly TransactionalOutboxEnvelope $outbox,
        private readonly IdentitySecurityEventRegistry $securityEventRegistry = new IdentitySecurityEventRegistry,
    ) {}

    /** @param array<string, mixed> $cloudEvent */
    public function insert(array $cloudEvent, string $aggregateId): void
    {
        OutboxEventType::from($cloudEvent['type']);

        $occurredAt = isset($cloudEvent['time']) && is_string($cloudEvent['time'])
            ? $cloudEvent['time']
            : now()->utc()->format('Y-m-d\TH:i:s.v\Z');

        $this->outbox->appendEnvelope(
            (string) $cloudEvent['id'],
            $aggregateId,
            $cloudEvent,
            $occurredAt,
        );
    }

    /**
     * Persist a security event whose payload is deliberately limited to opaque identifiers
     * and bounded machine-readable reason values. Secrets, credentials and PII never cross
     * this boundary.
     *
     * @param  array<string, mixed>  $data
     */
    public function insertSecurityEvent(string $type, string $aggregateId, array $data = []): void
    {
        $type = preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', $type) === 1 ? $type : 'security_event';
        $aggregateId = $this->isUuidV7($aggregateId) ? $aggregateId : Str::uuid7()->toString();
        $allowed = ['user_id', 'session_id', 'credential_id', 'password_version', 'lockout_level', 'source_hash', 'username_hash'];
        $safeData = [];
        foreach ($allowed as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            if (in_array($key, ['user_id', 'session_id', 'credential_id'], true) && is_string($data[$key]) && $this->isUuidV7($data[$key])) {
                $safeData[$key] = $data[$key];
            } elseif (in_array($key, ['source_hash', 'username_hash'], true) && is_string($data[$key]) && preg_match('/\A[0-9a-f]{64}\z/', $data[$key]) === 1) {
                $safeData[$key] = $data[$key];
            } elseif (in_array($key, ['password_version', 'lockout_level'], true) && is_int($data[$key])) {
                $safeData[$key] = $data[$key];
            }
        }
        $safeCodes = [
            'manual_logout', 'security_change', 'password_change',
            'binding_mismatch', 'session_expired',
            'invalid_credentials', 'source_rate_limited', 'account_rate_limited', 'credential_recovery_required',
            'throttled', 'account_unavailable', 'mfa_required', 'mfa_failed',
        ];
        foreach (['reason_code', 'failure_code'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && in_array($data[$key], $safeCodes, true)) {
                $safeData[$key] = $data[$key];
            }
        }
        $eventType = $this->identitySecurityEventType($type);
        $this->insert([
            'specversion' => '1.0',
            'id' => Str::uuid7()->toString(),
            'source' => '/identity',
            'type' => $eventType->value,
            'subject' => '/identity/users/'.$aggregateId,
            'time' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'datacontenttype' => 'application/json',
            'data' => $safeData,
        ], $aggregateId);
    }

    /**
     * Resolve a producer-supplied security-event suffix to its matching
     * `OutboxEventType` case via {@see IdentitySecurityEventRegistry}.
     *
     * The registry is the single source of truth for which suffixes
     * `insertSecurityEvent()` may emit; an unknown suffix raises
     * `InvalidArgumentException` here rather than slipping an
     * unregistered `com.cluster.identity.<suffix>.v1` literal past the
     * outbox helper. The CloudEvents `type` value comes from the enum
     * case so renaming a suffix only requires updating the registry.
     */
    private function identitySecurityEventType(string $type): OutboxEventType
    {
        return $this->securityEventRegistry->resolve($type);
    }

    private function isUuidV7(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1;
    }
}
