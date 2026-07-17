<?php

namespace Shared\Infrastructure\Streams;

interface RedisStreamTransport
{
    /** @param array<string, string> $fields */
    public function xadd(string $stream, array $fields): string;

    public function createGroup(string $stream, string $group): void;

    /** @return list<array{id: string, fields: array<string, string>, deliveries: int}> */
    public function readGroup(string $stream, string $group, string $consumer, int $limit): array;

    /** @return list<array{id: string, consumer: string, idle_ms: int, deliveries: int}> */
    public function pending(string $stream, string $group, int $limit): array;

    /**
     * @param  list<string>  $messageIds
     * @return list<array{id: string, fields: array<string, string>, deliveries: int}>
     */
    public function reclaim(
        string $stream,
        string $group,
        string $consumer,
        int $minimumIdleMilliseconds,
        array $messageIds,
    ): array;

    public function ack(string $stream, string $group, string $messageId): void;

    /** @param array<string, mixed> $deadLetter */
    public function publishDlq(string $stream, string $sourceMessageId, array $deadLetter): string;

    public function purgeDlq(string $stream): void;
}
