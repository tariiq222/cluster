<?php

use App\Http\Controllers\Organization\CreateClusterController;
use App\Http\Controllers\Organization\CreateFacilityController;
use App\Http\Controllers\Organization\CreateOrganizationUnitController;
use App\Http\Controllers\Organization\CreatePersonController;
use App\Http\Controllers\Organization\CreatePositionController;
use App\Http\Controllers\Organization\GetClusterController;
use App\Http\Controllers\Organization\GetFacilityController;
use App\Http\Controllers\Organization\GetOrganizationUnitController;
use App\Http\Controllers\Organization\GetPersonController;
use App\Http\Controllers\Organization\GetPersonReferenceController;
use App\Http\Controllers\Organization\GetPositionController;
use App\Http\Controllers\Organization\ListFacilitiesController;
use App\Http\Controllers\Organization\ListOrganizationUnitsController;
use App\Http\Controllers\Organization\ListPeopleController;
use App\Http\Controllers\Organization\ListPositionsController;
use App\Http\Controllers\Organization\UpdateClusterController;
use App\Http\Controllers\Organization\UpdateFacilityController;
use App\Http\Controllers\Organization\UpdateOrganizationUnitController;
use App\Http\Controllers\Organization\UpdatePersonController;
use App\Http\Controllers\Organization\UpdatePositionController;
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
    Route::get('organization/cluster', GetClusterController::class);
    Route::post('organization/cluster', CreateClusterController::class);
    Route::patch('organization/cluster', UpdateClusterController::class);
    Route::get('organization/facilities', ListFacilitiesController::class);
    Route::post('organization/facilities', CreateFacilityController::class);
    Route::get('organization/facilities/{facilityId}', GetFacilityController::class);
    Route::patch('organization/facilities/{facilityId}', UpdateFacilityController::class);
    Route::get('organization/units', ListOrganizationUnitsController::class);
    Route::post('organization/units', CreateOrganizationUnitController::class);
    Route::get('organization/units/{unitId}', GetOrganizationUnitController::class);
    Route::patch('organization/units/{unitId}', UpdateOrganizationUnitController::class);
    Route::get('organization/positions', ListPositionsController::class);
    Route::post('organization/positions', CreatePositionController::class);
    Route::get('organization/positions/{positionId}', GetPositionController::class);
    Route::patch('organization/positions/{positionId}', UpdatePositionController::class);
    Route::get('organization/people', ListPeopleController::class);
    Route::post('organization/people', CreatePersonController::class);
    Route::get('organization/people/{personId}/reference', GetPersonReferenceController::class);
    Route::get('organization/people/{personId}', GetPersonController::class);
    Route::patch('organization/people/{personId}', UpdatePersonController::class);
    Route::post('work-records', SubmitWorkRecordController::class);
    Route::get('work-records', ListAuthorizedWorkRecordsController::class);
    Route::get('work-records/{recordId}', GetAuthorizedWorkRecordController::class);
})->withoutMiddleware(['web', PreventRequestForgery::class]);
