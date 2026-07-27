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
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('audit_activity_items_invalid');
        }
        foreach ($items as $item) {
            if (! $item instanceof AuditActivityItem) {
                throw new InvalidArgumentException('audit_activity_items_invalid');
            }
        }
        if ($nextCursor !== null && ($nextCursor === '' || strlen($nextCursor) > 4096)) {
            throw new InvalidArgumentException('audit_cursor_invalid');
        }
    }
}
