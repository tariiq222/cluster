<?php

namespace Modules\PlatformSettings\Infrastructure;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Modules\PlatformSettings\Contracts\BackupOperationsGateway;
use Modules\PlatformSettings\Contracts\PlatformHealthGateway;
use Modules\PlatformSettings\Domain\HealthCheckResult;
use Shared\Contracts\OutboxRelayStore;
use Throwable;

final class LaravelPlatformHealthGateway implements PlatformHealthGateway
{
    /** @param array<string, callable(int): string> $probes */
    public function __construct(
        private readonly BackupOperationsGateway $backups,
        private readonly array $probes = [],
        private readonly ?OutboxRelayStore $outbox = null,
    ) {}

    /** @return list<HealthCheckResult> */
    public function snapshot(): array
    {
        $probes = $this->probes !== [] ? $this->probes : $this->defaultProbes();
        $checks = [];
        foreach ($probes as $code => $probe) {
            $checks[] = $this->check($code, $probe);
        }

        return $checks;
    }

    /** @return array<string, callable(int): string> */
    private function defaultProbes(): array
    {
        return [
            'database' => static function (int $timeoutMs): string {
                DB::connection()->getPdo();

                return 'reachable';
            },
            'redis' => static function (int $timeoutMs): string {
                Redis::connection()->ping();

                return 'reachable';
            },
            'storage' => static function (int $timeoutMs): string {
                Storage::disk((string) config('filesystems.default'))->files('', false);

                return 'reachable';
            },
            'queue' => static fn (int $timeoutMs): string => is_string(config('queue.default')) ? 'configured' : throw new \RuntimeException('queue_unavailable'),
            'outbox' => function (int $timeoutMs): string {
                if ($this->outbox === null) {
                    throw new \RuntimeException('outbox_unavailable');
                }
                $this->outbox->pending(['com.cluster.platform.technical-alert.v1'], 1);

                return 'reachable';
            },
            'file_scanning' => static fn (int $timeoutMs): string => config('documents') !== null ? 'configured' : throw new \RuntimeException('scanner_unavailable'),
            'notifications' => static fn (int $timeoutMs): string => is_string(config('mail.default')) ? 'configured' : throw new \RuntimeException('notifications_unavailable'),
            'backup' => fn (int $timeoutMs): string => $this->backups->status()->status === 'available' ? 'available' : 'unconfigured',
        ];
    }

    /** @param callable(int): string $probe */
    private function check(string $code, callable $probe): HealthCheckResult
    {
        $startedAt = hrtime(true);
        try {
            $messageCode = $this->runBoundedProbe($probe, (int) config('platform_operations.health.timeout_ms', 250));
            $status = $messageCode === 'unconfigured' ? 'degraded' : 'healthy';
        } catch (Throwable $exception) {
            $messageCode = str_contains(strtolower($exception::class), 'timeout') || str_contains(strtolower($exception->getMessage()), 'timeout')
                ? 'timeout'
                : 'unavailable';
            $status = 'degraded';
        }

        $latencyMs = (int) floor((hrtime(true) - $startedAt) / 1_000_000);
        if ($status === 'healthy' && $latencyMs > (int) config('platform_operations.health.timeout_ms', 250)) {
            $status = 'degraded';
            $messageCode = 'timeout';
        }

        return new HealthCheckResult(
            code: $code,
            status: $status,
            checkedAt: new DateTimeImmutable('now'),
            latencyMs: $latencyMs,
            messageCode: $messageCode,
        );
    }

    /** @param callable(int): string $probe */
    private function runBoundedProbe(callable $probe, int $timeoutMs): string
    {
        $timeoutMs = max(1, $timeoutMs);
        if (! function_exists('pcntl_alarm')
            || ! function_exists('pcntl_async_signals')
            || ! function_exists('pcntl_signal')
            || ! function_exists('pcntl_signal_get_handler')) {
            // A missing signal runtime must fail closed rather than run an unbounded probe.
            throw new \RuntimeException('health_probe_timeout');
        }

        $previousAsyncSignals = pcntl_async_signals(true);
        $previousHandler = pcntl_signal_get_handler(SIGALRM);
        pcntl_signal(SIGALRM, static function (): never {
            throw new \RuntimeException('health_probe_timeout');
        });
        pcntl_alarm((int) ceil($timeoutMs / 1000));

        try {
            return $probe($timeoutMs);
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            pcntl_async_signals($previousAsyncSignals);
        }
    }
}
