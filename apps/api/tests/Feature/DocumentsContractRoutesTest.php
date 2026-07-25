<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Documents\Features\DocumentLifecycle\Http\UpdateDocumentController;
use Modules\Documents\Features\DocumentLink\Http\LinkDocumentController;
use Modules\Documents\Features\DocumentLink\Http\ListDocumentLinksController;
use Modules\Documents\Features\DocumentVersion\Http\AddDocumentVersionController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class DocumentsContractRoutesTest extends TestCase
{
    /** @return array<string, string> */
    private function actionByMethodAndUri(): array
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/documents')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                $routes[$method.' '.$uri] = $route->getActionName();
            }
        }

        return $routes;
    }

    public function test_generated_document_contract_routes_are_wired_to_runtime_controllers(): void
    {
        $routes = $this->actionByMethodAndUri();

        $this->assertSame(UpdateDocumentController::class, $routes['PATCH api/v1/documents/{documentId}']);
        $this->assertSame(AddDocumentVersionController::class, $routes['POST api/v1/documents/{documentId}/versions']);
        $this->assertSame(ListDocumentLinksController::class, $routes['GET api/v1/documents/{documentId}/links']);
        $this->assertSame(LinkDocumentController::class, $routes['POST api/v1/documents/{documentId}/links']);
    }

    // The CSRF-on-PATCH regression test (added in stage 5) was reverted.
    // IdentityCsrfMiddleware runs after IdentitySessionMiddleware and
    // RequireIdentitySessionPrincipal, so a session-less PATCH is 401
    // long before the CSRF guard sees the request. A faithful unit test
    // for the guard would mock the session/principal resolution
    // entirely, which is a different test surface (see
    // apps/api/tests/Unit/Http/Middleware/IdentityCsrfMiddlewareTest.php
    // when it lands). Keeping the route-mapping test alone here is the
    // smallest, most-stable contract surface.
}
