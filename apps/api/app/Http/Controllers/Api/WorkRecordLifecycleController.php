<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Shared\Contracts\TransactionalOutbox;

final class WorkRecordLifecycleController
{
    use HttpSupport;

    public function __construct(private readonly ResolveDevelopmentFixturePrincipal $resolver, private readonly TransactionalOutbox $outbox) {}

    public function transition(Request $request, string $recordId, string $action): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        } $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        } $key = $this->commandHeaders($request);
        if ($key === '') {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $c);
        } $row = DB::table('work_records')->where('id', $recordId)->first();
        if ($row === null || $row->creator_user_id !== $p['user_id']) {
            return $this->problem(404, 'resource-not-found', 'The work record is not available.', $c);
        } $expected = $this->versionFromMatch($request);
        if ($expected === null || $expected !== (int) $row->lock_version) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        } $status = match ($action) {
            'submit' => 'submitted', 'return' => 'returned', 'complete', 'complete-submission' => 'completed', default => null
        };
        if ($status === null) {
            return $this->problem(409, 'invalid-record-transition', 'The record action is not supported.', $c);
        } DB::transaction(function () use ($recordId, $expected, $status, $action, $p): void {
            $updated = DB::table('work_records')->where('id', $recordId)->where('lock_version', $expected)->update(['status' => $status, 'lock_version' => $expected + 1, 'updated_at' => now(), 'submitted_at' => $status === 'submitted' ? now() : null]);
            if ($updated !== 1) {
                throw new \RuntimeException('stale');
            } $this->outbox->append(Str::uuid7()->toString(), $recordId, 'work_record.'.$action.'.v1', ['work_record_id' => $recordId, 'actor_user_id' => $p['user_id']]);
        });
        $result = (array) DB::table('work_records')->where('id', $recordId)->first();

        return $this->response($result, 200, $c, (int) $result['lock_version']);
    }
}
