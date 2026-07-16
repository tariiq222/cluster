<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Modules\Identity\Features\DevelopmentFixtureLogin\Http\DevelopmentFixtureLoginController;
use Modules\Notifications\Features\ListMyNotifications\Http\ListMyNotificationsController;
use Modules\WorkRecords\Features\GetAuthorizedWorkRecord\Http\GetAuthorizedWorkRecordController;
use Modules\WorkRecords\Features\ListAuthorizedWorkRecords\Http\ListAuthorizedWorkRecordsController;
use Modules\WorkRecords\Features\SubmitWorkRecord\Http\SubmitWorkRecordController;

Route::prefix('api/v1')->group(function (): void {
    Route::post('auth/login', DevelopmentFixtureLoginController::class)
        ->middleware('web')
        ->withoutMiddleware(PreventRequestForgery::class);
    Route::get('notifications', ListMyNotificationsController::class);
    Route::post('work-records', SubmitWorkRecordController::class);
    Route::get('work-records', ListAuthorizedWorkRecordsController::class);
    Route::get('work-records/{recordId}', GetAuthorizedWorkRecordController::class);
})->withoutMiddleware(['web', PreventRequestForgery::class]);
