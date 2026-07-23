<?php

namespace Modules\Workflow\Features\GetVisibleWorkflowInstance\Query;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class GetVisibleWorkflowInstance
{
    /** @return array<string, mixed>|null */
    public function fetch(string $id, string $user): ?array
    {
        $instance = $this->find($id);
        if ($instance === null) {
            return null;
        }
        if ($instance->started_by_user_id === $user) {
            return $this->tracking($instance);
        }
        $steps = $this->steps($id, $user);

        return $steps === [] ? null : $this->tracking($instance, $steps);
    }

    public function find(string $id): ?object
    {
        return DB::table('workflow_instances')->where('id', $id)->first();
    }

    /** @return array<string, mixed> */
    public function fetchForOperations(object $instance): array
    {
        return $this->tracking($instance);
    }

    /** @return array{items: list<array<string, mixed>>, next_cursor: ?string} */
    public function owned(string $user, ?string $state, ?string $cursor, int $limit): array
    {
        $query = DB::table('workflow_instances')->where('started_by_user_id', $user);
        if ($state !== null) {
            $query->where('state', $state);
        }
        if ($cursor !== null) {
            $value = $this->cursor($cursor);
            $query->where(fn ($q) => $q
                ->where('created_at', '>', $value['created_at'])
                ->orWhere(fn ($same) => $same
                    ->where('created_at', $value['created_at'])
                    ->where('id', '>', $value['id'])));
        }
        $rows = $query->orderBy('created_at')->orderBy('id')->limit($limit + 1)->get()->all();
        $hasNext = count($rows) > $limit;
        if ($hasNext) {
            array_pop($rows);
        }

        return [
            'items' => array_map(fn ($row): array => $this->tracking($row), $rows),
            'next_cursor' => $hasNext ? $this->encode(end($rows)) : null,
        ];
    }

    /** @param list<object>|null $steps @return array<string, mixed> */
    private function tracking(object $i, ?array $steps = null): array
    {
        $steps ??= $this->steps((string) $i->id);
        $owner = collect($steps)->first(fn ($s) => in_array($s->state, ['waiting', 'active'], true) && $s->assignee_user_id);

        return [
            'id' => (string) $i->id,
            'resource_type' => 'workflow_instance',
            'status' => (string) $i->state,
            'classification' => 'internal',
            'lock_version' => (int) $i->lock_version,
            'created_at' => Carbon::parse($i->created_at)->utc()->toIso8601String(),
            'updated_at' => Carbon::parse($i->updated_at)->utc()->toIso8601String(),
            'workflow_version_id' => (string) $i->workflow_version_id,
            'source_module' => (string) $i->source_module,
            'source_type' => (string) $i->source_type,
            'source_id' => (string) $i->source_id,
            'state' => (string) $i->state,
            'current_owner_user_id' => $owner?->assignee_user_id,
            'age_seconds' => Carbon::parse($i->started_at ?? $i->created_at)->diffInSeconds(now()),
            'step_history' => array_map(fn ($step): array => $this->stepHistory($step), $steps),
        ];
    }

    /** @return array<string, mixed> */
    private function stepHistory(object $step): array
    {
        return [
            'step_id' => (string) $step->id,
            'workflow_instance_id' => (string) $step->workflow_instance_id,
            'lock_version' => (int) $step->lock_version,
            'node_key' => (string) $step->node_key,
            'node_type' => (string) $step->node_type,
            'state' => (string) $step->state,
            'assignee_user_id' => $step->assignee_user_id,
            'activated_at' => $step->activated_at ? Carbon::parse($step->activated_at)->utc()->toIso8601String() : null,
            'completed_at' => $step->completed_at ? Carbon::parse($step->completed_at)->utc()->toIso8601String() : null,
            'actor_user_id' => null,
            'decision' => null,
            'reason' => null,
        ];
    }

    /** @return list<object> */
    private function steps(string $id, ?string $user = null): array
    {
        $q = DB::table('workflow_step_instances')->where('workflow_instance_id', $id);
        if ($user !== null) {
            $q->where('assignee_user_id', $user);
        }

        return $q->orderBy('created_at')->orderBy('id')->get()->all();
    }

    private function encode(object $r): string
    {
        return rtrim(strtr(base64_encode(json_encode(['created_at' => (string) $r->created_at, 'id' => (string) $r->id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{created_at: string, id: string} */
    private function cursor(string $cursor): array
    {
        $raw = base64_decode(strtr($cursor.str_repeat('=', (4 - strlen($cursor) % 4) % 4), '-_', '+/'), true);
        try {
            $value = json_decode($raw ?: '', true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new InvalidArgumentException;
        }
        if (! is_array($value) || ! is_string($value['created_at'] ?? null) || ! is_string($value['id'] ?? null) || $value['id'] === '') {
            throw new InvalidArgumentException('Invalid workflow instance cursor.');
        }
        try {
            Carbon::parse($value['created_at']);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid workflow instance cursor.');
        }

        return ['created_at' => $value['created_at'], 'id' => $value['id']];
    }
}
