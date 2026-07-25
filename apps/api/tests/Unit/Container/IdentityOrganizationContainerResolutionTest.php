<?php

namespace Tests\Unit\Container;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Domain\PasswordPolicy;
use Modules\Identity\Features\Authentication\Contracts\AuthenticateUser;
use Modules\Identity\Features\Authentication\Handler\AuthenticationHandler;
use Modules\Identity\Features\Credentials\Contracts\ChangePassword;
use Modules\Identity\Features\Credentials\Contracts\UsernameDenylist;
use Modules\Identity\Features\Sessions\Contracts\ResolveSession;
use Modules\Identity\Features\Sessions\Handler\SessionHandler;
use Modules\Identity\Infrastructure\Security\LocalUsernameDenylist;
use Modules\Identity\Infrastructure\Security\PersistentPreAuthThrottle;
use Modules\Organization\Contracts\GetActiveSupervisoryRelationships;
use Modules\Organization\Contracts\GetDefaultClusterId;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use Modules\Organization\Contracts\ResolvePersonOrganizationScope;
use Modules\Organization\Contracts\ResolveQuarantinedImport;
use Modules\Organization\Contracts\ValidatePersonReference;
use Modules\Organization\Features\TemporaryAssignment\Contracts\ValidateTemporaryAssignmentCapabilities;
use Modules\Organization\Features\TemporaryAssignment\Http\TemporaryAssignmentHttpGateway;
use Modules\Organization\Infrastructure\Authorization\ConfiguredTemporaryAssignmentCapabilityValidator;
use Modules\Organization\Infrastructure\Import\UnavailableQuarantinedImport;
use Modules\Organization\Infrastructure\Persistence\DatabaseGetActiveSupervisoryRelationships;
use Modules\Organization\Infrastructure\Persistence\DatabaseGetDefaultClusterId;
use Modules\Organization\Infrastructure\Persistence\DatabaseResolveOrganizationScopeAncestry;
use Modules\Organization\Infrastructure\Persistence\DatabaseResolvePersonOrganizationScope;
use Modules\Organization\Infrastructure\Persistence\ValidatePersonReferenceFromPersistence;
use Tests\TestCase;

/**
 * Locks down the composition-root wiring for Identity and Organization
 * contracts so container resolution becomes a contract test:
 *
 * - Every production contract exposed by the two modules is bound to the
 *   expected concrete implementation.
 * - The bound consumer classes resolve via the container without manual
 *   construction in tests.
 * - PasswordPolicy requires UsernameDenylist as a non-nullable constructor
 *   dependency (the type system itself enforces the requirement that the
 *   previous runtime guard used to check).
 */
final class IdentityOrganizationContainerResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_username_denylist_contract_resolves_to_the_local_production_implementation(): void
    {
        $this->assertInstanceOf(LocalUsernameDenylist::class, $this->app->make(UsernameDenylist::class));
    }

    public function test_password_policy_pulls_the_bound_denylist_and_does_not_fall_back_to_an_inline_default(): void
    {
        config(['identity.password.denylist.path' => __DIR__.'/Fixtures/identity-denylist.txt']);
        $policy = $this->app->make(PasswordPolicy::class);

        $this->assertContains('common_password', $policy->violations('Known-Leak-Password'));
    }

    public function test_password_policy_constructor_requires_a_non_nullable_denylist(): void
    {
        $reflection = new \ReflectionMethod(PasswordPolicy::class, '__construct');
        $parameters = $reflection->getParameters();
        $this->assertSame('denylist', $parameters[0]->getName());
        $this->assertNotNull($parameters[0]->getType());
        $this->assertFalse($parameters[0]->allowsNull(), 'UsernameDenylist must be a required non-nullable constructor dependency.');
        $this->assertSame(UsernameDenylist::class, $parameters[0]->getType()->getName());
    }

    public function test_authentication_handler_resolves_with_the_bound_persistent_throttle(): void
    {
        $handler = $this->app->make(AuthenticationHandler::class);

        $this->assertInstanceOf(AuthenticationHandler::class, $handler);
        $this->assertInstanceOf(PersistentPreAuthThrottle::class, $this->app->make(PersistentPreAuthThrottle::class));
    }

    public function test_identity_consumer_contracts_resolve_to_their_handler_or_resolver(): void
    {
        $this->assertInstanceOf(AuthenticationHandler::class, $this->app->make(AuthenticateUser::class));
        $this->assertInstanceOf(SessionHandler::class, $this->app->make(ResolveSession::class));
        $this->assertInstanceOf(\Modules\Identity\Features\Credentials\Handler\CredentialHandler::class, $this->app->make(ChangePassword::class));
    }

    public function test_organization_contracts_each_resolve_to_their_database_adapter(): void
    {
        $this->assertInstanceOf(DatabaseGetActiveSupervisoryRelationships::class, $this->app->make(GetActiveSupervisoryRelationships::class));
        $this->assertInstanceOf(DatabaseGetDefaultClusterId::class, $this->app->make(GetDefaultClusterId::class));
        $this->assertInstanceOf(DatabaseResolveOrganizationScopeAncestry::class, $this->app->make(ResolveOrganizationScopeAncestry::class));
        $this->assertInstanceOf(DatabaseResolvePersonOrganizationScope::class, $this->app->make(ResolvePersonOrganizationScope::class));
        $this->assertInstanceOf(ValidatePersonReferenceFromPersistence::class, $this->app->make(ValidatePersonReference::class));
    }

    public function test_resolve_quarantined_import_remains_explicitly_unavailable_outside_test_storage(): void
    {
        $this->assertInstanceOf(UnavailableQuarantinedImport::class, $this->app->make(ResolveQuarantinedImport::class));
    }

    public function test_organization_temporary_assignment_contracts_resolve_to_their_configured_implementation(): void
    {
        $this->assertInstanceOf(ConfiguredTemporaryAssignmentCapabilityValidator::class, $this->app->make(ValidateTemporaryAssignmentCapabilities::class));
        $this->assertInstanceOf(\Modules\Organization\Features\TemporaryAssignment\Http\DatabaseTemporaryAssignmentHttpGateway::class, $this->app->make(TemporaryAssignmentHttpGateway::class));
    }
}
