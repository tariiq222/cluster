<?php

declare(strict_types=1);

namespace Modules\Audit\Features\ListAuditEvents\Handler;

use Modules\Audit\Contracts\AuditActivityPage;
use Modules\Audit\Contracts\AuditActivityQuery;
use Modules\Audit\Contracts\QueryAuditActivity;

final class ListAuditEventsHandler
{
    public function __construct(private readonly QueryAuditActivity $activity) {}

    public function handle(AuditActivityQuery $query): AuditActivityPage
    {
        return $this->activity->query($query);
    }
}
