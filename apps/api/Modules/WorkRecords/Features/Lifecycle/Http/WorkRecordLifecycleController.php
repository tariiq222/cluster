<?php

namespace Modules\WorkRecords\Features\Lifecycle\Http;

use Illuminate\Http\Request;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\WorkRecords\Features\Lifecycle\Handler\WorkRecordLifecycleMutator;
use Shared\Http\HttpSupport;

final class WorkRecordLifecycleController
{
    use HttpSupport;

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $resolver,
        private readonly WorkRecordLifecycleMutator $mutator,
    ) {}

    public function transition(Request $request, string $recordId, string $action): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }
        $key = $this->commandHeaders($request);
        if ($key === '') {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $c);
        }
        $expected = $this->versionFromMatch($request);
        if ($expected === null) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        }

        $result = $this->mutator->transition($recordId, $action, $p, $c, $expected);
        if (! $result['ok']) {
            $problem = $result['problem'];

            return $this->problem($problem['status'], $problem['type'], $problem['detail'], $c);
        }

        return $this->response(
            $result['access_projection']->compose($this->serialize($result['result']), function (array $payload, array $fieldAccess): array {
                $wildcard = $fieldAccess['*'] ?? null;
                foreach ($payload as $field => $value) {
                    $state = $fieldAccess[$field] ?? $wildcard;
                    if ($state === 'hidden') {
                        unset($payload[$field]);
                    } elseif ($state === 'masked') {
                        $payload[$field] = '***';
                    }
                }

                return $payload;
            }),
            200,
            $c,
            (int) $result['result']['lock_version'],
        );
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function serialize(array $row): array
    {
        $payload = $row['payload'] ?? [];
        if (is_string($payload)) {
            $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        }

        return [
            'id' => $row['id'],
            'record_number' => $row['record_number'],
            'work_type_version_id' => $row['work_type_version_id'],
            'owner' => [
                'facility_id' => $row['owner_facility_id'],
                'user_id' => $row['creator_user_id'],
            ],
            'status' => $row['status'],
            'classification' => $row['classification'],
            'payload' => is_array($payload) ? $payload : [],
            'lock_version' => (int) $row['lock_version'],
            'submitted_at' => $row['submitted_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
