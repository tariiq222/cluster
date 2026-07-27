<?php

declare(strict_types=1);

namespace Modules\Audit\Http;

/**
 * Public HTTP surface for the Audit module. The actual routes, JSON
 * problem shapes, and middleware stack are owned by the per-feature
 * controllers; this class documents the surface and provides a single
 * import point for the openapi.yaml mapping.
 */
final class AuditApi
{
    public const ROUTE_PREFIX = '/api/v1/audit';

    public const ROUTE_LIST = '/api/v1/audit/events';

    public const ROUTE_GET = '/api/v1/audit/events/{eventId}';

    public const ROUTE_CREATE_EXPORT = '/api/v1/audit/exports';

    public const ROUTE_GET_EXPORT = '/api/v1/audit/exports/{exportId}';

    public const ROUTE_DOWNLOAD_EXPORT = '/api/v1/audit/exports/{exportId}/artifact';

    public const ROUTE_VERIFY_INTEGRITY = '/api/v1/audit/integrity/verify';
}
