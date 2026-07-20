<?php

namespace Modules\Reporting\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

/**
 * GET /api/v1/reports — lists published report definitions after the central
 * decision; definitions are cluster-level metadata and never embed rows.
 */
final class ListReportsController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = ReportingApi::correlationId($request);
        if ($correlationId === null) {
            return ReportingApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $principal = ReportingApi::principalOrProblem($request, $this->principalResolver, $correlationId);
        if ($principal instanceof JsonResponse) {
            return $principal;
        }

        $limit = (int) $request->query('limit', 25);
        if ($limit < 1 || $limit > 100) {
            return ReportingApi::problem(400, 'invalid-pagination', 'Bad Request', 'The collection parameters are invalid.', $correlationId);
        }

        $clusterId = DB::table('clusters')->orderBy('code')->value('id');
        $decision = $this->access->decide(
            [
                'user_id' => $principal['user_id'],
                'facility_id' => $principal['facility_id'],
                'organization_unit_ids' => array_filter([$principal['facility_id']]),
                'correlation_id' => $correlationId,
            ],
            'reporting.list',
            new RecordFacts(
                ownerFacilityId: null,
                resourceType: 'report_definition',
                classification: 'internal',
                clusterId: is_string($clusterId) ? $clusterId : null,
            ),
        );
        if (! $decision->isAllowed()) {
            return ReportingApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $query = DB::table('report_definitions')->where('status', 'published')->orderBy('id');
        $cursor = $request->query('cursor');
        if (is_string($cursor) && $cursor !== '') {
            $query->where('id', '>', $cursor);
        }
        $rows = $query->limit($limit + 1)->get()->all();
        $hasNextPage = count($rows) > $limit;
        if ($hasNextPage) {
            array_pop($rows);
        }

        return ReportingApi::response([
            'items' => array_map(static fn (\stdClass $row): array => [
                'id' => $row->id,
                'code' => $row->code,
                'title' => $row->title,
                'status' => $row->status,
                'projection_version' => $row->projection_version,
            ], $rows),
            'next_cursor' => $hasNextPage && $rows !== [] ? (string) end($rows)->id : null,
        ], 200, $correlationId);
    }
}
