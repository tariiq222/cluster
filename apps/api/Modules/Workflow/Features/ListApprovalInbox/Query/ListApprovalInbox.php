<?php

namespace Modules\Workflow\Features\ListApprovalInbox\Query;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Server-filtered approval inbox query owned by the Workflow module. */
final class ListApprovalInbox
{
    private const STATES = ['waiting', 'active', 'completed', 'rejected', 'returned', 'cancelled'];

    /**
     * @param  null|callable(object): list<string>  $allowedActions
     * @param  null|callable(list<object>): array<string, true>  $visibleStepIds
     * @return array{items: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function execute(string $assigneeUserId, ?string $state, int $limit, ?string $cursor = null, ?callable $allowedActions = null, ?callable $visibleStepIds = null): array
    {
        if ($state !== null && $state !== 'all' && ! in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException('Invalid workflow inbox state.');
        }

        if ($visibleStepIds === null) {
            $rows = $this->rows($assigneeUserId, $state, $cursor, $limit + 1);
            $hasNextPage = count($rows) > $limit;
            if ($hasNextPage) {
                array_pop($rows);
            }

            return [
                'items' => array_map(fn (object $step): array => $this->project($step, $allowedActions), $rows),
                'next_cursor' => $hasNextPage && $rows !== [] ? $this->encodeCursor(end($rows)) : null,
            ];
        }

        $rows = $this->filteredRows($assigneeUserId, $state, $limit, $cursor, $visibleStepIds);
        $hasNextPage = count($rows) > $limit;
        if ($hasNextPage) {
            array_pop($rows);
        }

        return [
            'items' => array_map(fn (object $step): array => $this->project($step, $allowedActions), $rows),
            'next_cursor' => $hasNextPage && $rows !== [] ? $this->encodeCursor(end($rows)) : null,
        ];
    }

    /** @return list<object> */
    private function filteredRows(string $assigneeUserId, ?string $state, int $limit, ?string $cursor, callable $visibleStepIds): array
    {
        $visible = [];
        $scanCursor = $cursor;
        $batchSize = 100;
        while (count($visible) <= $limit) {
            $batch = $this->rows($assigneeUserId, $state, $scanCursor, $batchSize);
            if ($batch === []) {
                break;
            }
            $allowedIds = $visibleStepIds($batch);
            foreach ($batch as $row) {
                if (isset($allowedIds[(string) $row->id])) {
                    $visible[] = $row;
                    if (count($visible) > $limit) {
                        return $visible;
                    }
                }
            }
            if (count($batch) < $batchSize) {
                break;
            }
            $scanCursor = $this->encodeCursor(end($batch));
        }

        return $visible;
    }

    /** @return list<object> */
    private function rows(string $assigneeUserId, ?string $state, ?string $cursor, int $limit): array
    {
        $query = DB::table('workflow_step_instances as steps')
            ->join('workflow_instances as instances', 'instances.id', '=', 'steps.workflow_instance_id')
            ->select('steps.*', 'instances.source_module', 'instances.source_type', 'instances.source_id', 'instances.state as workflow_instance_state')
            ->where('steps.assignee_user_id', $assigneeUserId);
        if ($state !== null && $state !== 'all') {
            $query->where('steps.state', $state);
        }
        $this->applyCursor($query, $cursor);

        return $query->orderBy('steps.created_at')->orderBy('steps.id')->limit($limit)->get()->all();
    }

    private function applyCursor(Builder $query, ?string $cursor): void
    {
        if ($cursor === null || $cursor === '') {
            return;
        }

        $decoded = $this->decodeCursor($cursor);
        $query->where(function (Builder $nested) use ($decoded): void {
            $nested->where('steps.created_at', '>', $decoded['created_at'])
                ->orWhere(function (Builder $sameTimestamp) use ($decoded): void {
                    $sameTimestamp->where('steps.created_at', '=', $decoded['created_at'])
                        ->where('steps.id', '>', $decoded['id']);
                });
        });
    }

    /** @return array{created_at: string, id: string} */
    private function decodeCursor(string $cursor): array
    {
        $padding = strlen($cursor) % 4;
        $decoded = base64_decode(strtr($cursor.str_repeat('=', $padding === 0 ? 0 : 4 - $padding), '-_', '+/'), true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid workflow inbox cursor.');
        }

        try {
            $value = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('Invalid workflow inbox cursor.');
        }

        if (! is_array($value) || ! is_string($value['created_at'] ?? null) || ! is_string($value['id'] ?? null) || $value['id'] === '') {
            throw new InvalidArgumentException('Invalid workflow inbox cursor.');
        }

        try {
            Carbon::parse($value['created_at']);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid workflow inbox cursor.');
        }

        return ['created_at' => $value['created_at'], 'id' => $value['id']];
    }

    private function encodeCursor(object $step): string
    {
        $json = json_encode([
            'created_at' => (string) $step->created_at,
            'id' => (string) $step->id,
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @param  null|callable(object): list<string>  $allowedActions
     * @return array<string, mixed>
     */
    private function project(object $step, ?callable $allowedActions): array
    {
        $state = (string) ($step->state ?? '');

        return [
            'step_id' => (string) $step->id,
            'workflow_instance_id' => (string) $step->workflow_instance_id,
            'source_type' => (string) $step->source_type,
            'source_id' => (string) $step->source_id,
            'state' => $state,
            'assignee_user_id' => (string) ($step->assignee_user_id ?? ''),
            'created_at' => Carbon::parse((string) ($step->created_at ?? 'now'))->utc()->toIso8601String(),
            'lock_version' => (int) ($step->lock_version ?? 1),
            'allowed_actions' => $allowedActions === null ? [] : $allowedActions($step),
        ];
    }
}
