<?php

declare(strict_types=1);

namespace Modules\Tasks\Features\TransitionTask\Exception;

use DomainException;

/**
 * Controller maps this exception onto HTTP 409 (invalid-task-transition /
 * missing reason/note / terminal state). The message is intentionally a
 * machine-readable code; the API layer renders the human-readable detail.
 */
final class TaskTransitionConflict extends DomainException
{
    public function __construct(string $code)
    {
        parent::__construct($code);
    }
}
