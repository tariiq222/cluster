<?php

declare(strict_types=1);

namespace Shared\Contracts;

/**
 * Raised when the Shared outbox adapter rejects an
 * {@see OutboxDuplicatePolicy::Replayable} write because the same
 * `event_id` is already persisted with a different payload hash.
 *
 * Carries the colliding event id so consumers can correlate retry
 * logs and HTTP problem payloads (intended mapping is 409 Conflict,
 * matching the domain-event conflict semantic the architecture plan
 * requires).
 */
final class OutboxConflictException extends \DomainException
{
    public function __construct(
        public readonly string $eventId,
        string $message = 'Outbox event collision: same id with a different payload hash.',
    ) {
        parent::__construct($message);
    }

    public static function forEvent(string $eventId): self
    {
        return new self($eventId);
    }

    /** HTTP problem status. Surfaced to callers as 409 Conflict. */
    public function statusCode(): int
    {
        return 409;
    }
}
