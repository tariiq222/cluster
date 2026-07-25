<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Routes;

use App\Http\Middleware\IdentityCsrfMiddleware;
use App\Http\Middleware\IdentitySessionMiddleware;
use App\Http\Middleware\RequireIdentitySessionPrincipal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Tasks\Features\Http\TaskController;
use Tests\TestCase;

/**
 * Routing-layer regression for the PATCH /tasks/{taskId} CSRF hotfix.
 *
 * The IdentityCsrfMiddleware is the only guard that proves a same-origin
 * caller issued the mutation. Before the route move, PATCH /tasks/{taskId}
 * sat in the session-only read group, so a malicious page could send the
 * PATCH cross-site and IdentitySessionMiddleware would have authorized
 * the user without checking the CSRF proof. After the move, the route
 * inherits IdentityCsrfMiddleware from the existing mutation group and
 * missing CSRF tokens are rejected with 403 csrf-failed.
 */
final class TaskRouteMutationMiddlewareTest extends TestCase
{
    public function test_patch_tasks_route_resolves_to_task_controller_update_action(): void
    {
        $routes = $this->routesByMethodAndUri();

        $this->assertArrayHasKey('PATCH api/v1/tasks/{taskId}', $routes);
        $this->assertSame(
            TaskController::class.'@update',
            $routes['PATCH api/v1/tasks/{taskId}'],
        );
    }

    public function test_patch_tasks_route_sits_in_the_csrf_mutation_group(): void
    {
        $middleware = $this->middlewareFor('PATCH', 'api/v1/tasks/{taskId}');

        $this->assertContains(IdentitySessionMiddleware::class, $middleware);
        $this->assertContains(RequireIdentitySessionPrincipal::class, $middleware);
        $this->assertContains(
            IdentityCsrfMiddleware::class,
            $middleware,
            'PATCH /tasks/{taskId} must run IdentityCsrfMiddleware to reject cross-site mutations.',
        );
    }

    public function test_get_tasks_route_remains_in_the_session_only_group_without_csrf(): void
    {
        $middleware = $this->middlewareFor('GET', 'api/v1/tasks/{taskId}');

        $this->assertContains(IdentitySessionMiddleware::class, $middleware);
        $this->assertContains(RequireIdentitySessionPrincipal::class, $middleware);
        $this->assertNotContains(
            IdentityCsrfMiddleware::class,
            $middleware,
            'GET /tasks/{taskId} must stay on the session-only read group; CSRF is for mutations only.',
        );
    }

    public function test_existing_csrf_protected_task_mutations_share_the_same_group(): void
    {
        $patchMiddleware = $this->middlewareFor('PATCH', 'api/v1/tasks/{taskId}');
        $postStoreMiddleware = $this->middlewareFor('POST', 'api/v1/tasks');
        $postTransitionMiddleware = $this->middlewareFor('POST', 'api/v1/tasks/{taskId}/start');

        $this->assertContains(IdentityCsrfMiddleware::class, $postStoreMiddleware);
        $this->assertContains(IdentityCsrfMiddleware::class, $postTransitionMiddleware);
        $this->assertLessThan(
            array_search(RequireIdentitySessionPrincipal::class, $patchMiddleware, true),
            array_search(IdentitySessionMiddleware::class, $patchMiddleware, true),
        );
        $this->assertLessThan(
            array_search(IdentityCsrfMiddleware::class, $patchMiddleware, true),
            array_search(RequireIdentitySessionPrincipal::class, $patchMiddleware, true),
        );
    }

    /** @return array<string, string> */
    private function routesByMethodAndUri(): array
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/tasks')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                $routes[$method.' '.$uri] = $route->getActionName();
            }
        }

        return $routes;
    }

    /** @return list<string> */
    private function middlewareFor(string $method, string $uri): array
    {
        $route = Route::getRoutes()->match(
            Request::create('/'.$uri, $method),
        );

        return array_values(array_map(
            static fn (object|string $middleware): string => is_object($middleware)
                ? $middleware::class
                : (string) $middleware,
            $route->gatherMiddleware(),
        ));
    }
}