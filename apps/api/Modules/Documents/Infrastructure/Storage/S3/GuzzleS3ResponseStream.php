<?php

namespace Modules\Documents\Infrastructure\Storage\S3;

use Psr\Http\Message\ResponseInterface;

/**
 * Pull-based {@see S3ResponseStream} wrapper around a Guzzle streaming
 * response. The wrapper owns the underlying {@see ResponseInterface} and
 * detaches its body when the stream is closed so the socket is released.
 */
final class GuzzleS3ResponseStream implements S3ResponseStream
{
    private bool $closed = false;

    public function __construct(private readonly ResponseInterface $response) {}

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function headers(): array
    {
        $headers = [];
        foreach ($this->response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        return $headers;
    }

    public function readChunk(int $chunkBytes): ?string
    {
        if ($this->closed) {
            return null;
        }
        if ($chunkBytes < 1) {
            return '';
        }
        $body = $this->response->getBody();
        if (! $body->isReadable() || $body->eof()) {
            $this->close();

            return null;
        }
        $chunk = $body->read($chunkBytes);
        if ($chunk === '') {
            $this->close();

            return null;
        }

        return $chunk;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $body = $this->response->getBody();
        if ($body->isReadable()) {
            $body->close();
        }
    }
}
