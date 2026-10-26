<?php

use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\ComplianceCheckController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('auth/token', [AuthTokenController::class, 'store'])
        ->middleware('throttle:auth-token')
        ->name('api.v1.auth.token');

    Route::post('compliance/audit', [ComplianceCheckController::class, 'audit'])
        ->middleware(['auth:sanctum', 'abilities:compliance:audit'])
        ->name('api.v1.compliance.audit');

    Route::post('contracts/parse', [ComplianceCheckController::class, 'parse'])
        ->middleware(['auth:sanctum', 'abilities:contracts:parse'])
        ->name('api.v1.contracts.parse');
});
