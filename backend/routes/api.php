<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\MatterDeadlineController;
use App\Http\Controllers\ClientPortalController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\MatterController;
use App\Http\Controllers\Api\V1\UserController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    Route::post('/matters/{matter}/deadlines/calculate', [MatterDeadlineController::class, 'calculate']);
    
    Route::apiResource('clients', ClientController::class);
    Route::apiResource('matters', MatterController::class);
    Route::apiResource('users', UserController::class);
});

// Client Portal API Routes
Route::prefix('client')->group(function () {
    Route::get('/matters', [ClientPortalController::class, 'getMatters']);
    Route::get('/matters/{matter}/trust-balance', [ClientPortalController::class, 'getTrustBalance']);
});
