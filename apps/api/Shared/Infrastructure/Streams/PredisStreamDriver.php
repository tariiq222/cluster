<?php

namespace Shared\Infrastructure\Streams;

use Predis\Client as PredisClient;
use Throwable;

/**
 * Thin wrapper around a Predis\Client that surfaces the stream commands in
 * the same uniform shape as PhpRedis. Predis performs its own key prefixing
 * through {@see \Predis\Command\Processor\KeyPrefixProcessor}, so we hand it
 * positional arguments and never pre-prefix any keys. Predis also applies
 * KEYS prefixing to Lua scripts automatically (see Predis\Command\Redis\EVAL_
 * ::prefixKeys), so EVAL scripts receive raw key names.
 */
final class PredisStreamDriver implements RedisStreamDriver
{
    public function __construct(private readonly PredisClient $client) {}

    public function xadd(string $stream, array $fields): string
    {
        $messageId = $this->client->xadd($stream, $fields, '*');

        if ($messageId === '') {
            throw new \RuntimeException('Redis did not return a stream message identifier.');
        }

        return $messageId;
    }

    public function createGroup(string $stream, string $group): void
    {
        try {
            $this->client->executeCommand(
                $this->client->createCommand('XGROUP', ['CREATE', $stream, $group, '0', true]),
            );
        } catch (Throwable $exception) {
            if (str_contains($exception->getMessage(), 'BUSYGROUP')) {
                return;
            }

            throw $exception;
        }
    }

    public function readGroup(string $stream, string $group, string $consumer, int $limit): array
    {
        $response = $this->client->executeCommand(
            $this->client->createCommand('XREADGROUP', [$group, $consumer, $limit, null, false, $stream, '>']),
        );

        return is_array($response) ? $response : [];
    }

    public function pending(string $stream, string $group, int $limit): array
    {
        return $this->client->xpending($stream, $group, null, '-', '+', $limit);
    }

    public function pendingSummary(string $stream, string $group, string $messageId): array
    {
        return $this->client->xpending($stream, $group, null, $messageId, $messageId, 1);
    }

    public function reclaim(string $stream, string $group, string $consumer, int $minimumIdleMilliseconds, array $messageIds): array
    {
        return $this->client->xclaim($stream, $group, $consumer, $minimumIdleMilliseconds, $messageIds);
    }

    public function ack(string $stream, string $group, string $messageId): void
    {
        $this->client->xack($stream, $group, $messageId);
    }

    public function eval(string $script, array $keys, array $arguments): mixed
    {
        return $this->client->eval($script, count($keys), ...$this->flattenLuaArgs($keys, $arguments));
    }

    /** @param list<string> $keys */
    /** @param list<string> $arguments */
    private function flattenLuaArgs(array $keys, array $arguments): array
    {
        return array_merge($keys, $arguments);
    }
}
