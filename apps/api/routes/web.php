<?php

use Illuminate\Support\Facades\Route;
use Modules\Identity\Features\DevelopmentFixtureLogin\Http\DevelopmentFixtureLoginController;

Route::prefix('api/v1')->group(function (): void {
    Route::post('auth/login', DevelopmentFixtureLoginController::class);
});
