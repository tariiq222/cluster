<?php

declare(strict_types=1);

namespace Modules\Audit\Features\ListAuditEvents\Http;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Audit\Contracts\AuditActivityQuery;
use Modules\Audit\Features\ListAuditEvents\Handler\ListAuditEventsHandler;
use Modules\Audit\Http\AuditApi;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Shared\Http\AuthenticatedCursorCodec;

final class ListAuditEventsController
{
    /** @var list<string> */
    private const QUERY_KEYS = [
        'cursor',
        'source_module',
        'action',
        'actor_id',
        'subject_type',
        'subject_id',
        'correlation_id',
        'classification',
        'occurred_from',
        'occurred_to',
        'limit',
    ];

    public function __construct(
        private readonly ResolvePrincipalContext $principals,
        private readonly DecideAccess $access,
        private readonly ListAuditEventsHandler $handler,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = AuditApi::correlationId($request);
        $principal = $this->principals->resolve($request);
        if ($principal === null) {
            return AuditApi::problem(
                401,
                'authentication-required',
                'Unauthorized',
                'Authentication is required.',
                $correlationId,
            );
        }

        $scope = AuditApi::scope($principal);
        $baseDecision = AuditApi::collectionDecision(
            $principal,
            $scope,
            $correlationId,
            $this->access,
        );
        if (! $baseDecision->isAllowed()) {
            return AuditApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }
        if ($correlationId === null) {
            return AuditApi::problem(
                400,
                'invalid-correlation-id',
                'Bad Request',
                'X-Correlation-ID must be a lowercase UUIDv7.',
            );
        }

        if ($this->hasUnknownQuery($request)) {
            return AuditApi::problem(
                400,
                'invalid-query-parameter',
                'Bad Request',
                'The query contains an unsupported parameter.',
                $correlationId,
            );
        }

        $invalidValueKey = $this->invalidQueryValueKey($request);
        if ($invalidValueKey !== null) {
            return in_array($invalidValueKey, ['cursor', 'limit'], true)
                ? $this->invalidPagination($correlationId)
                : AuditApi::problem(
                    400,
                    'invalid-filter',
                    'Bad Request',
                    'One or more audit filters are invalid.',
                    $correlationId,
                );
        }

        $limit = $this->limit($request);
        if ($limit === null) {
            return $this->invalidPagination($correlationId);
        }
        $cursor = $this->nullableString($request, 'cursor');
        if ($request->query->has('cursor') && $cursor === null) {
            return $this->invalidPagination($correlationId);
        }

        try {
            $query = new AuditActivityQuery(
                principalId: $principal->userId,
                facilityId: $scope['facility_id'],
                organizationUnitIds: $scope['organization_unit_ids'],
                cursor: $cursor,
                sourceModule: $this->nullableString($request, 'source_module'),
                action: $this->nullableString($request, 'action'),
                actorId: $this->nullableString($request, 'actor_id'),
                subjectType: $this->nullableString($request, 'subject_type'),
                subjectId: $this->nullableString($request, 'subject_id'),
                correlationId: $this->nullableString($request, 'correlation_id'),
                classification: $this->nullableString($request, 'classification'),
                occurredFrom: $this->dateTime($request, 'occurred_from'),
                occurredTo: $this->dateTime($request, 'occurred_to'),
                limit: $limit,
            );
        } catch (InvalidArgumentException) {
            return $cursor !== null
                ? $this->invalidPagination($correlationId)
                : AuditApi::problem(
                    400,
                    'invalid-filter',
                    'Bad Request',
                    'One or more audit filters are invalid.',
                    $correlationId,
                );
        }

        try {
            $page = $this->handler->handle($query);
        } catch (InvalidArgumentException $exception) {
            if ($exception->getMessage() !== AuthenticatedCursorCodec::INVALID_CURSOR_MESSAGE) {
                throw $exception;
            }

            return $this->invalidPagination($correlationId);
        }

        $response = AuditApi::response([
            'items' => array_map(AuditApi::serialize(...), $page->items),
            'next_cursor' => $page->nextCursor,
        ], $correlationId);
        if ($page->nextCursor !== null) {
            $response->header(
                'Link',
                '<'.$request->fullUrlWithQuery(['cursor' => $page->nextCursor]).'>; rel="next"',
            );
        }

        return $response;
    }

    private function hasUnknownQuery(Request $request): bool
    {
        foreach (array_keys($request->query()) as $key) {
            if (! is_string($key) || ! in_array($key, self::QUERY_KEYS, true)) {
                return true;
            }
        }

        return false;
    }

    private function nullableString(Request $request, string $key): ?string
    {
        if (! $request->query->has($key)) {
            return null;
        }
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function invalidQueryValueKey(Request $request): ?string
    {
        foreach (self::QUERY_KEYS as $key) {
            if (! $request->query->has($key)) {
                continue;
            }
            $value = $request->query($key);
            if (! is_string($value) || $value === '') {
                return $key;
            }
        }

        return null;
    }

    private function limit(Request $request): ?int
    {
        if (! $request->query->has('limit')) {
            return 25;
        }
        $raw = $request->query('limit');
        if (! is_string($raw) || preg_match('/\A(?:[1-9]|[1-9][0-9]|100)\z/', $raw) !== 1) {
            return null;
        }

        return (int) $raw;
    }

    private function dateTime(Request $request, string $key): ?DateTimeImmutable
    {
        if (! $request->query->has($key)) {
            return null;
        }
        $raw = $request->query($key);
        if (! is_string($raw)) {
            throw new InvalidArgumentException('audit_datetime_invalid');
        }
        $value = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.v\Z',
            $raw,
            new DateTimeZone('UTC'),
        );
        if ($value === false || $value->format('Y-m-d\TH:i:s.v\Z') !== $raw) {
            throw new InvalidArgumentException('audit_datetime_invalid');
        }

        return $value;
    }

    private function invalidPagination(string $correlationId): JsonResponse
    {
        return AuditApi::problem(
            400,
            'invalid-pagination',
            'Bad Request',
            'The pagination parameters are invalid.',
            $correlationId,
        );
    }
}
