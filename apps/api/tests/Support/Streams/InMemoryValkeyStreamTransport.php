<?php

namespace Tests\Support\Streams;

use Closure;
use RuntimeException;
use Shared\Infrastructure\Streams\ValkeyStreamTransport;

final class InMemoryValkeyStreamTransport implements ValkeyStreamTransport
{
    /** @var array<string, list<array{id: string, fields: array<string, string>}>> */
    private array $streams = [];

    /** @var array<string, array<string, array{last_index: int, pending: array<string, array{consumer: string, delivered_at_ms: int, deliveries: int}>}>> */
    private array $groups = [];

    /** @var array<string, array{timestamp: int, sequence: int}> */
    private array $lastIds = [];

    private readonly Closure $clock;

    private int $ackFailuresRemaining = 0;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock === null
            ? static fn (): int => (int) floor(microtime(true) * 1000)
            : Closure::fromCallable($clock);
    }

    public function xadd(string $stream, array $fields): string
    {
        $messageId = $this->nextMessageId($stream);
        $this->streams[$stream][] = [
            'id' => $messageId,
            'fields' => $fields,
        ];

        return $messageId;
    }

    public function createGroup(string $stream, string $group): void
    {
        $this->streams[$stream] ??= [];
        $this->groups[$stream][$group] ??= [
            'last_index' => -1,
            'pending' => [],
        ];
    }

    public function readGroup(string $stream, string $group, string $consumer, int $limit): array
    {
        $state = &$this->group($stream, $group);
        $messages = [];
        $maximum = max(0, $limit);
        $streamEntries = $this->streams[$stream] ?? [];

        for ($index = $state['last_index'] + 1; $index < count($streamEntries) && count($messages) < $maximum; $index++) {
            $entry = $streamEntries[$index];
            $state['last_index'] = $index;
            $state['pending'][$entry['id']] = [
                'consumer' => $consumer,
                'delivered_at_ms' => $this->nowMilliseconds(),
                'deliveries' => 1,
            ];
            $messages[] = [
                ...$entry,
                'deliveries' => 1,
            ];
        }

        return $messages;
    }

    public function pending(string $stream, string $group, int $limit): array
    {
        $state = &$this->group($stream, $group);
        $pending = [];

        foreach ($state['pending'] as $messageId => $delivery) {
            if (count($pending) >= max(0, $limit)) {
                break;
            }

            $pending[] = [
                'id' => $messageId,
                'consumer' => $delivery['consumer'],
                'idle_ms' => max(0, $this->nowMilliseconds() - $delivery['delivered_at_ms']),
                'deliveries' => $delivery['deliveries'],
            ];
        }

        return $pending;
    }

    public function reclaim(
        string $stream,
        string $group,
        string $consumer,
        int $minimumIdleMilliseconds,
        array $messageIds,
    ): array {
        $state = &$this->group($stream, $group);
        $messages = [];

        foreach ($messageIds as $messageId) {
            $delivery = $state['pending'][$messageId] ?? null;
            if ($delivery === null
                || $this->nowMilliseconds() - $delivery['delivered_at_ms'] < $minimumIdleMilliseconds) {
                continue;
            }

            $entry = $this->entry($stream, $messageId);
            if ($entry === null) {
                continue;
            }

            $delivery['consumer'] = $consumer;
            $delivery['delivered_at_ms'] = $this->nowMilliseconds();
            $delivery['deliveries']++;
            $state['pending'][$messageId] = $delivery;
            $messages[] = [
                ...$entry,
                'deliveries' => $delivery['deliveries'],
            ];
        }

        return $messages;
    }

    public function ack(string $stream, string $group, string $messageId): void
    {
        if ($this->ackFailuresRemaining > 0) {
            $this->ackFailuresRemaining--;

            throw new RuntimeException('CONTROLLED_POST_COMMIT_PRE_ACK_CRASH');
        }

        $state = &$this->group($stream, $group);
        unset($state['pending'][$messageId]);
    }

    public function publishDlq(string $stream, array $deadLetter): string
    {
        return $this->xadd($stream, [
            'event' => json_encode($deadLetter, JSON_THROW_ON_ERROR),
        ]);
    }

    public function failNextAck(int $times = 1): void
    {
        $this->ackFailuresRemaining = max(0, $times);
    }

    /** @return list<array{id: string, fields: array<string, string>}> */
    public function streamEntries(string $stream): array
    {
        return $this->streams[$stream] ?? [];
    }

    private function nextMessageId(string $stream): string
    {
        $now = $this->nowMilliseconds();
        $last = $this->lastIds[$stream] ?? null;
        if ($last !== null && $now <= $last['timestamp']) {
            $timestamp = $last['timestamp'];
            $sequence = $last['sequence'] + 1;
        } else {
            $timestamp = $now;
            $sequence = 0;
        }

        $this->lastIds[$stream] = compact('timestamp', 'sequence');

        return $timestamp.'-'.$sequence;
    }

    /** @return array{last_index: int, pending: array<string, array{consumer: string, delivered_at_ms: int, deliveries: int}>} */
    private function &group(string $stream, string $group): array
    {
        if (! isset($this->groups[$stream][$group])) {
            throw new RuntimeException('The in-memory Valkey consumer group does not exist.');
        }

        return $this->groups[$stream][$group];
    }

    /** @return array{id: string, fields: array<string, string>}|null */
    private function entry(string $stream, string $messageId): ?array
    {
        foreach ($this->streams[$stream] ?? [] as $entry) {
            if ($entry['id'] === $messageId) {
                return $entry;
            }
        }

        return null;
    }

    private function nowMilliseconds(): int
    {
        return ($this->clock)();
    }
}
