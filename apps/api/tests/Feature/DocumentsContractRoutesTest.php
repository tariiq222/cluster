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
}
