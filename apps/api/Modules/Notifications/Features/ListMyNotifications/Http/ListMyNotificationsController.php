<?php

namespace Modules\Notifications\Features\ListMyNotifications\Http;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use JsonException;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use stdClass;

final class ListMyNotificationsController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = $this->correlationId($request);
        if ($correlationId === null) {
            return $this->problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }

        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return $this->problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }

        $queryParameters = $request->query();
        $validator = Validator::make($queryParameters, [
            'cursor' => ['sometimes', 'string', 'min:1', 'max:2048'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        if ($validator->fails() || array_diff(array_keys($queryParameters), ['cursor', 'limit']) !== []) {
            return $this->invalidQuery($correlationId);
        }

        $validated = $validator->validated();
        $limit = (int) ($validated['limit'] ?? 25);
        try {
            $after = isset($validated['cursor'])
                ? $this->decodeCursor($validated['cursor'], $principal['user_id'], $limit)
                : null;
        } catch (InvalidArgumentException) {
            return $this->invalidQuery($correlationId);
        }

        $query = DB::table('notifications')
            ->where('recipient_user_id', $principal['user_id'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
        if ($after !== null) {
            $query->where(function (Builder $query) use ($after): void {
                $query->where('created_at', '<', $after['created_at'])
                    ->orWhere(function (Builder $query) use ($after): void {
                        $query->where('created_at', $after['created_at'])
                            ->where('id', '<', $after['id']);
                    });
            });
        }

        $rows = $query->limit($limit + 1)->get();
        $hasNextPage = $rows->count() > $limit;
        if ($hasNextPage) {
            $rows->pop();
        }
        $lastRow = $rows->last();

        return response()->json([
            'items' => $rows->map(fn (stdClass $row): array => $this->serialize($row, $principal))->values()->all(),
            'next_cursor' => $hasNextPage && $lastRow instanceof stdClass
                ? $this->encodeCursor($lastRow, $principal['user_id'], $limit)
                : null,
        ])->header('X-Correlation-ID', $correlationId);
    }

    /**
     * A notification never leaks a source the recipient can no longer read:
     * the central decision is re-evaluated against the stored source facts
     * and denied sources are masked (title and reference), not just hidden
     * in the UI.
     *
     * @param  array{user_id: string, facility_id: string}  $principal
     * @return array{id: string, title: string, source: array{source_module: string, record_type: string, record_id: string}, is_read: bool, created_at: string}
     */
    private function serialize(stdClass $row, array $principal): array
    {
        $masked = false;
        $sourceFacilityId = $row->source_owner_facility_id ?? null;
        if (is_string($sourceFacilityId) && $sourceFacilityId !== '') {
            $decision = $this->access->decide(
                [
                    'user_id' => $principal['user_id'],
                    'facility_id' => $principal['facility_id'],
                    'organization_unit_ids' => array_filter([$principal['facility_id']]),
                ],
                'work_record.read',
                new RecordFacts(
                    ownerFacilityId: $sourceFacilityId,
                    resourceType: 'work_record',
                    classification: is_string($row->source_classification ?? null) ? $row->source_classification : 'internal',
                    recordId: (string) $row->source_record_id,
                ),
            );
            $masked = ! $decision->isAllowed();
        }

        return [
            'id' => (string) $row->id,
            'title' => $masked ? 'إشعار غير متاح حالياً' : (string) $row->title,
            'source' => [
                'source_module' => 'work_records',
                'record_type' => 'work_record',
                'record_id' => $masked ? '' : (string) $row->source_record_id,
            ],
            'is_read' => (bool) $row->is_read,
            'created_at' => $this->timestamp((string) $row->created_at),
        ];
    }

    private function timestamp(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    private function encodeCursor(stdClass $row, string $principalId, int $limit): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 1,
            'after' => [
                'created_at' => (string) $row->created_at,
                'id' => (string) $row->id,
            ],
            'query' => ['limit' => $limit],
            'scope' => ['principal_id' => $principalId],
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{created_at: string, id: string} */
    private function decodeCursor(string $cursor, string $principalId, int $limit): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new InvalidArgumentException('The notifications cursor is invalid.');
        }

        if (! is_array($payload)
            || array_keys($payload) !== ['version', 'after', 'query', 'scope']
            || $payload['version'] !== 1
            || ! is_array($payload['after'])
            || array_keys($payload['after']) !== ['created_at', 'id']
            || ! is_string($payload['after']['created_at'])
            || ! is_string($payload['after']['id'])
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $payload['after']['id']) !== 1
            || ! is_array($payload['query'])
            || $payload['query'] !== ['limit' => $limit]
            || ! is_array($payload['scope'])
            || array_keys($payload['scope']) !== ['principal_id']
            || ! is_string($payload['scope']['principal_id'])
            || ! hash_equals($principalId, $payload['scope']['principal_id'])) {
            throw new InvalidArgumentException('The notifications cursor is invalid.');
        }

        return $payload['after'];
    }

    private function invalidQuery(string $correlationId): JsonResponse
    {
        return $this->problem(
            400,
            'invalid-notifications-query',
            'Bad Request',
            'The notifications query is invalid.',
            $correlationId,
        );
    }

    private function correlationId(Request $request): ?string
    {
        $value = $request->header('X-Correlation-ID');

        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1
            ? $value
            : null;
    }

    private function problem(int $status, string $type, string $title, string $detail, ?string $correlationId = null): JsonResponse
    {
        $response = response()->json([
            'type' => "https://cluster.example/problems/{$type}",
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $status)->header('Content-Type', 'application/problem+json');

        return $correlationId === null ? $response : $response->header('X-Correlation-ID', $correlationId);
    }
}
