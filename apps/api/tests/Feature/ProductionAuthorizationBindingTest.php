<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Infrastructure\BootstrapGatedDecideAccess;
use Modules\Authorization\Infrastructure\FixtureFacilityDecision;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Identity\Features\ResolveDevelopmentFixturePrincipal\Http\DevelopmentFixturePrincipalResolver;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * Proves the production runtime cannot resolve the fixture decision engine or
 * the development bearer principal on user-facing paths.
 */
final class ProductionAuthorizationBindingTest extends TestCase
{
    #[RunInSeparateProcess]
    public function test_production_binds_the_real_engine_and_session_principals(): void
    {
        $this->putEnv('production');
        $this->refreshApplication();

        $this->assertInstanceOf(BootstrapGatedDecideAccess::class, $this->app->make(DecideAccess::class));
        $this->assertNotInstanceOf(FixtureFacilityDecision::class, $this->app->make(DecideAccess::class));

        $principal = $this->app->make(ResolveDevelopmentFixturePrincipal::class);
        $this->assertNotSame(DevelopmentFixturePrincipalResolver::class, get_class($principal));

        $this->postJson('/api/v1/auth/login', ['username' => 'fixture-account-a', 'password' => 'fixture-password-a'])
            ->assertNotFound();

        $this->putEnv('testing');
    }

    #[RunInSeparateProcess]
    public function test_production_boot_guard_rejects_fixture_bindings(): void
    {
        $this->putEnv('production');
        $this->refreshApplication();
        $this->app->bind(DecideAccess::class, FixtureFacilityDecision::class);

        $provider = new AppServiceProvider($this->app);
        $guard = new \ReflectionMethod(AppServiceProvider::class, 'assertAuthorizationRuntimeSafe');
        $guard->setAccessible(true);

        $thrown = null;
        try {
            $guard->invoke($provider);
        } catch (\RuntimeException $exception) {
            $thrown = $exception;
        }

        $this->putEnv('testing');
        $this->assertNotNull($thrown, 'The boot guard must fail when the fixture engine is bound in production.');
        $this->assertStringContainsString('RBAC+ABAC', $thrown->getMessage());
    }

    private function putEnv(string $environment): void
    {
        putenv('APP_ENV='.$environment);
        $_ENV['APP_ENV'] = $environment;
        $_SERVER['APP_ENV'] = $environment;
        // Keep the documents production runtime (S3/ClamAV assertions) out of
        // the binding assertions; "test" mirrors `php artisan test` argv.
        $_SERVER['argv'] = ['artisan', 'test'];
    }
}
