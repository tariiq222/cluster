<?php

namespace Modules\Identity\Features\Sessions\Contracts;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Cookie;

final readonly class SessionTransport
{
    public function __construct(
        public string $userId,
        public string $sessionId,
        public string $csrfToken,
        public DateTimeImmutable $expiresAt,
        public Cookie $cookie,
        public bool $restricted,
    ) {}

    /** @return array{user_id: string, session_id: string, csrf_token: string, expires_at: string, restricted: bool} */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'session_id' => $this->sessionId,
            'csrf_token' => $this->csrfToken,
            'expires_at' => $this->expiresAt->format('Y-m-d\TH:i:s\Z'),
            'restricted' => $this->restricted,
        ];
    }
}
