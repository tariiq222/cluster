<?php

namespace Tests\Feature;

use App\Http\Controllers\Documents\AddDocumentVersionController;
use App\Http\Controllers\Documents\LinkDocumentController;
use App\Http\Controllers\Documents\ListDocumentLinksController;
use App\Http\Controllers\Documents\UpdateDocumentController;
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

    public function test_patch_documents_without_csrf_header_is_rejected(): void
    {
        $this->withoutMiddleware([
            \App\Http\Middleware\EnforcePlatformMaintenance::class,
        ]);

        $uri = '/api/v1/documents/00000000-0000-7000-8000-000000000001';
        $headers = ['X-Correlation-ID' => '018f6f7d-0c00-7000-8000-000000000101'];

        $this->patchJson($uri, ['title' => 'x'], $headers)->assertStatus(403);

        $login = $this->postJson('/api/v1/identity/login', [
            'username' => 'fixture-account-a',
            'password' => 'fixture-password-a',
        ], $headers)->assertOk();
        $cookie = $login->headers->getCookies()[0]->getValue();
        $csrf = (string) $login->json('data.csrf_token');

        $this->withUnencryptedCookie('cluster_identity_session', $cookie)->withCredentials()
            ->patchJson($uri, ['title' => 'x'], [...$headers, 'X-CSRF-Token' => $csrf])
            ->assertStatus(404);
    }
}
