<?php

namespace Modules\Documents\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Documents\Domain\UuidV7;

final readonly class SignedUploadIntent
{
    /** @param array<string, string> $requiredHeaders */
    public function __construct(
        public string $id,
        public string $url,
        public string $method,
        public DateTimeImmutable $expiresAt,
        public array $requiredHeaders,
    ) {
        UuidV7::assert($this->id, 'Upload intent id');
        if (! filter_var($this->url, FILTER_VALIDATE_URL) || ! str_starts_with($this->url, 'https://')) {
            throw new InvalidArgumentException('Signed upload URL must be HTTPS.');
        }
        if (! in_array($this->method, ['PUT', 'POST'], true)) {
            throw new InvalidArgumentException('Signed upload method is unsupported.');
        }
    }

    /** @return array{id: string, url: string, method: string, expires_at: string, required_headers: array<string, string>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'method' => $this->method,
            'expires_at' => $this->expiresAt->format('Y-m-d\TH:i:s.v\Z'),
            'required_headers' => $this->requiredHeaders,
        ];
    }

    /** @param array{id: mixed, url: mixed, method: mixed, expires_at: mixed, required_headers: mixed} $payload */
    public static function fromArray(array $payload): self
    {
        if (! is_string($payload['id'])
            || ! is_string($payload['url'])
            || ! is_string($payload['method'])
            || ! is_string($payload['expires_at'])
            || ! is_array($payload['required_headers'])) {
            throw new InvalidArgumentException('Stored signed upload intent is invalid.');
        }
        $headers = [];
        foreach ($payload['required_headers'] as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                throw new InvalidArgumentException('Stored signed upload intent headers are invalid.');
            }
            $headers[$name] = $value;
        }

        return new self(
            $payload['id'],
            $payload['url'],
            $payload['method'],
            new DateTimeImmutable($payload['expires_at']),
            $headers,
        );
    }
}
