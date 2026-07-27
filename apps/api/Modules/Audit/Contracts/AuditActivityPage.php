<?php

declare(strict_types=1);

namespace Modules\Audit\Contracts;

use InvalidArgumentException;

final readonly class AuditActivityPage
{
    /**
     * @param  list<AuditActivityItem>  $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
        if ($nextCursor !== null && ($nextCursor === '' || strlen($nextCursor) > 4096)) {
            throw new InvalidArgumentException('audit_cursor_invalid');
        }
    }
}
