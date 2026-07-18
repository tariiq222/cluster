<?php

namespace Modules\Documents\Infrastructure\Authorization;

use Illuminate\Http\Request;
use LogicException;
use Modules\Documents\Contracts\WorkerPrincipalResolver;

final readonly class ConfiguredWorkerPrincipalResolver implements WorkerPrincipalResolver
{
    public function __construct(
        private string $token,
        private string $userId,
        private string $organizationUnitId,
    ) {
        if (strlen($this->token) < 32
            || ! $this->isUuidV7($this->userId)
            || ! $this->isUuidV7($this->organizationUnitId)) {
            throw new LogicException('Documents worker authentication is not configured safely.');
        }
    }

    public function issue(array $principal): array
    {
        throw new LogicException('Documents worker credentials cannot be issued through the HTTP resolver.');
    }

    public function resolve(Request $request): ?array
    {
        $provided = $request->header('X-Documents-Worker-Token');
        if (! is_string($provided) || ! hash_equals($this->token, $provided)) {
            return null;
        }

        return [
            'user_id' => $this->userId,
            'facility_id' => $this->organizationUnitId,
        ];
    }

    private function isUuidV7(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1;
    }
}
