<?php

namespace Modules\Identity\Providers;

use App\Http\Authentication\SessionPrincipalResolver;
use Illuminate\Support\ServiceProvider;
use Modules\Identity\Contracts\ResolveAccountEntitlement;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Modules\Identity\Contracts\ResolveUserForPerson;
use Modules\Identity\Features\Activation\Contracts\IssueActivationToken;
use Modules\Identity\Features\Activation\Handler\ActivationHandler;
use Modules\Identity\Features\Authentication\Contracts\AuthenticateUser;
use Modules\Identity\Features\Authentication\Contracts\PreAuthThrottle;
use Modules\Identity\Features\Authentication\Handler\AuthenticationHandler;
use Modules\Identity\Features\Credentials\Contracts\ChangePassword;
use Modules\Identity\Features\Credentials\Handler\CredentialHandler;
use Modules\Identity\Features\ResolveDevelopmentFixturePrincipal\Http\DevelopmentFixturePrincipalResolver;
use Modules\Identity\Features\Sessions\Contracts\ResolveSession;
use Modules\Identity\Features\Sessions\Handler\SessionHandler;
use Modules\Identity\Infrastructure\DatabaseResolveAccountEntitlement;
use Modules\Identity\Infrastructure\Persistence\ResolveUserForPerson as DatabaseResolveUserForPerson;
use Modules\Identity\Infrastructure\Security\PersistentPreAuthThrottle;
use Modules\Identity\Domain\PasswordPolicy;
use Modules\Identity\Features\Credentials\Contracts\UsernameDenylist;
use Modules\Identity\Infrastructure\Security\LocalUsernameDenylist;
use Modules\Identity\Infrastructure\SessionPrincipalContextResolver;

final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UsernameDenylist::class, LocalUsernameDenylist::class);
        $this->app->singleton(PasswordPolicy::class, function ($app): PasswordPolicy {
            return new PasswordPolicy($app->make(UsernameDenylist::class), $app->make(\Modules\PlatformSettings\Contracts\GetEffectivePlatformSettings::class));
        });
        $this->app->bind(ResolvePrincipalContext::class, SessionPrincipalContextResolver::class);
        $this->app->bind(ResolveAccountEntitlement::class, DatabaseResolveAccountEntitlement::class);
        $this->app->bind(ResolveUserForPerson::class, DatabaseResolveUserForPerson::class);
        $this->app->bind(AuthenticateUser::class, AuthenticationHandler::class);
        $this->app->bind(PreAuthThrottle::class, PersistentPreAuthThrottle::class);
        $this->app->bind(IssueActivationToken::class, ActivationHandler::class);
        $this->app->bind(ChangePassword::class, CredentialHandler::class);
        $this->app->bind(ResolveSession::class, SessionHandler::class);
        $this->app->singleton(ResolveDevelopmentFixturePrincipal::class, function (): ResolveDevelopmentFixturePrincipal {
            if (! $this->developmentFixturesAllowed()) {
                return $this->app->make(SessionPrincipalResolver::class);
            }

            return $this->app->make(DevelopmentFixturePrincipalResolver::class);
        });
    }

    private function developmentFixturesAllowed(): bool
    {
        return app()->environment('local') || app()->environment('testing');
    }
}
