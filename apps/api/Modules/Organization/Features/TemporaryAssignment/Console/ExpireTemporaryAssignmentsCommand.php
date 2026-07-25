<?php

namespace Modules\Organization\Features\TemporaryAssignment\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class ExpireTemporaryAssignmentsCommand extends Command
{
    private const MAX_BATCH_SIZE = 500;

    private const SYSTEM_SUBJECT_ID = '00000000-0000-7000-8000-000000000001';

    protected $description = 'Expire one bounded batch of temporary assignments';

    protected $signature = 'organization:expire-temporary-assignments
        {--once : Required bounded execution mode}
        {--limit=100 : Number of assignments to expire (1-500)}';

    public function __construct(private readonly RunTemporaryAssignmentExpiration $expiration)
    {
        parent::__construct();
    }

    /** @return array{expired_count: int, expired_ids: list<string>, has_more: bool} */
    public function __invoke(int $limit): array
    {
        if ($limit < 1 || $limit > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException('temporary_assignment_expiration_limit_invalid');
        }

        return $this->expiration->run(
            $limit,
            self::SYSTEM_SUBJECT_ID,
            Str::uuid7()->toString(),
        );
    }

    public function handle(): int
    {
        $limit = $this->option('limit');
        if (! $this->option('once')
            || ! is_string($limit)
            || preg_match('/\A[1-9][0-9]{0,2}\z/', $limit) !== 1
            || (int) $limit > self::MAX_BATCH_SIZE) {
            $this->error('The bounded --once mode and a --limit between 1 and 500 are required.');

            return self::INVALID;
        }

        try {
            $result = $this((int) $limit);
        } catch (Throwable) {
            $this->error('The bounded temporary-assignment expiration cycle failed.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Expired %d temporary assignment(s); more due: %s.',
            $result['expired_count'],
            $result['has_more'] ? 'yes' : 'no',
        ));

        return self::SUCCESS;
    }
}
