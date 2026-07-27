<?php

declare(strict_types=1);

namespace Modules\Audit\Features\GetAuditEvent\Handler;

use Modules\Audit\Contracts\AuditActivityItem;
use Modules\Audit\Contracts\AuditActivityQuery;
use Modules\Audit\Infrastructure\Persistence\DatabaseQueryAuditActivity;

final class GetAuditEventHandler
{
    public function __construct(private readonly DatabaseQueryAuditActivity $activity) {}

    public function handle(AuditActivityQuery $scope, string $eventId): ?AuditActivityItem
    {
        return $this->activity->findAuthorized($scope, $eventId);
    }
}
