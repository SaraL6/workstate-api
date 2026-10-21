<?php

use App\Http\Controllers\Api\V1\ComplianceCheckController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('compliance/audit', [ComplianceCheckController::class, 'audit'])
        ->name('api.v1.compliance.audit');

    Route::post('contracts/parse', [ComplianceCheckController::class, 'parse'])
        ->name('api.v1.contracts.parse');
});
