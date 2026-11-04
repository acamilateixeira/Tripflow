<?php

use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\CostCenterBudgetController;
use App\Http\Controllers\Api\V1\TravelRequestController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthController::class);

Route::prefix('v1')->group(function () {
    Route::post('auth/token', AuthTokenController::class)->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::get('travel-requests', [TravelRequestController::class, 'index']);
        Route::post('travel-requests', [TravelRequestController::class, 'store'])->middleware('idempotent');
        Route::get('travel-requests/{travelRequest}', [TravelRequestController::class, 'show']);
        Route::post('travel-requests/{travelRequest}/submit', [TravelRequestController::class, 'submit']);
        Route::post('travel-requests/{travelRequest}/approve', [TravelRequestController::class, 'approve']);
        Route::post('travel-requests/{travelRequest}/reject', [TravelRequestController::class, 'reject']);
        Route::post('travel-requests/{travelRequest}/bookings', [TravelRequestController::class, 'book'])->middleware('idempotent');
        Route::post('travel-requests/{travelRequest}/cancel', [TravelRequestController::class, 'cancel']);

        Route::get('cost-centers/{costCenter}/budget', CostCenterBudgetController::class);
    });
});
