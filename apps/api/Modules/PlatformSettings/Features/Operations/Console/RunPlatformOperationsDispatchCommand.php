<?php

namespace Modules\PlatformSettings\Features\Operations\Console;

use Illuminate\Console\Command;
use Modules\PlatformSettings\Features\Operations\Handler\PlatformOperationsDispatchHandler;
use Throwable;

final class RunPlatformOperationsDispatchCommand extends Command
{
    private const MAX_BATCH_SIZE = 100;

    protected $description = 'Run one bounded batch of platform backup and restore-validation dispatches';

    protected $signature = 'platform-operations:dispatch
        {--once : Required bounded execution mode}
        {--limit=10 : Number of operations to dispatch (1-100)}';

    public function __construct(private readonly PlatformOperationsDispatchHandler $dispatcher)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = $this->option('limit');
        if (! $this->option('once')
            || ! is_string($limit)
            || preg_match('/\A[1-9][0-9]{0,2}\z/', $limit) !== 1
            || (int) $limit > self::MAX_BATCH_SIZE) {
            $this->error('The bounded --once mode and a --limit between 1 and 100 are required.');

            return self::INVALID;
        }

        try {
            $processed = $this->dispatcher->run((int) $limit);
        } catch (Throwable) {
            $this->error('The platform operations dispatch cycle failed.');

            return self::FAILURE;
        }

        $this->info("Dispatched {$processed} platform operation(s).");

        return self::SUCCESS;
    }
}
