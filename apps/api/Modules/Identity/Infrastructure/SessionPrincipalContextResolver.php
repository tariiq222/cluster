<?php

namespace Modules\Identity\Infrastructure;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Modules\Identity\Contracts\PrincipalContext;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Modules\Identity\Features\UserAccount\Handler\UserAccountHandler;
use Modules\Organization\Contracts\ResolvePersonOrganizationScope;
use stdClass;
use Throwable;

/**
 * Builds the trusted PrincipalContext server-side from the validated Identity
 * session attribute, the account record and Organization scope facts. Runs
 * after the session middleware has enforced account status, revocation,
 * binding metadata and MFA; every remaining failure mode still fails closed.
 */
final class SessionPrincipalContextResolver implements ResolvePrincipalContext
{
    private const SESSION_ATTRIBUTE = 'identity.session';

    public function __construct(
        private readonly UserAccountHandler $accounts,
        private readonly ResolvePersonOrganizationScope $organizationScope,
        private readonly ConnectionInterface $persistence,
    ) {}

    public function resolve(Request $request): ?PrincipalContext
    {
        $session = $this->session($request);
        if ($session === null) {
            return null;
        }

        $account = $this->accounts->find($session['user_id'])['account'] ?? null;
        if (! is_array($account) || ($account['status'] ?? null) !== 'active') {
            return null;
        }

        $scope = $this->scope(is_string($account['person_id'] ?? null) ? $account['person_id'] : null);
        if ($scope === null) {
            return null;
        }

        return new PrincipalContext(
            userId: $session['user_id'],
            personId: is_string($account['person_id'] ?? null) ? $account['person_id'] : null,
            accountStatus: (string) $account['status'],
            clusterIds: $scope['cluster_ids'],
            facilityIds: $scope['facility_ids'],
            organizationUnitIds: $scope['organization_unit_ids'],
            primaryOrganizationUnitId: $scope['primary_organization_unit_id'],
            selectedScope: $this->resolveSelectedScope($request),
            sessionRestricted: $session['restricted'],
        );
    }

    public function resolveSelectedScope(Request $request): ?array
    {
        $session = $this->session($request);
        if ($session === null) {
            return null;
        }

        $selected = $this->metadata($session)['selected_scope'] ?? null;

        return is_array($selected)
            && is_string($selected['scope_type'] ?? null)
            && is_string($selected['scope_id'] ?? null)
            ? ['scope_type' => $selected['scope_type'], 'scope_id' => $selected['scope_id']]
            : null;
    }

    public function persistSelectedScope(Request $request, string $scopeType, string $scopeId): void
    {
        $session = $this->session($request);
        if ($session === null) {
            return;
        }

        $metadata = $this->metadata($session);
        $metadata['selected_scope'] = ['scope_type' => $scopeType, 'scope_id' => $scopeId];
        $this->persistence->table('identity_sessions')->where('id', $session['session_id'])->update([
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    /** @return array{user_id: string, session_id: string, restricted: bool}|null */
    private function session(Request $request): ?array
    {
        $session = $request->attributes->get(self::SESSION_ATTRIBUTE);
        if (! is_array($session)
            || ! is_string($session['user_id'] ?? null)
            || ! is_string($session['session_id'] ?? null)
            || ($session['restricted'] ?? true) !== false) {
            return null;
        }

        return [
            'user_id' => $session['user_id'],
            'session_id' => $session['session_id'],
            'restricted' => false,
        ];
    }

    /**
     * Accounts without a person link carry an empty scope; a failing
     * Organization contract fails closed like any other trust violation.
     *
     * @return array{cluster_ids: list<string>, facility_ids: list<string>, organization_unit_ids: list<string>, primary_organization_unit_id: ?string}|null
     */
    private function scope(?string $personId): ?array
    {
        if ($personId === null) {
            return [
                'cluster_ids' => [],
                'facility_ids' => [],
                'organization_unit_ids' => [],
                'primary_organization_unit_id' => null,
            ];
        }

        try {
            return $this->organizationScope->forPerson($personId);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array{user_id: string, session_id: string, restricted: bool}  $session
     * @return array<string, mixed>
     */
    private function metadata(array $session): array
    {
        $row = $this->persistence->table('identity_sessions')->where('id', $session['session_id'])->first(['metadata']);
        if (! $row instanceof stdClass || ! is_string($row->metadata)) {
            return [];
        }

        $metadata = json_decode($row->metadata, true);

        return is_array($metadata) ? $metadata : [];
    }
}
