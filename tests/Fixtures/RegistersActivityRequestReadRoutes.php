<?php

namespace Tests\Fixtures;

use App\Http\Controllers\ActivityRequests\ActivityRequestController;
use Illuminate\Support\Facades\Route;

/** Registers protected read contracts only in the test application's router. */
trait RegistersActivityRequestReadRoutes
{
    private function registerActivityRequestReadRoutes(): void
    {
        Route::prefix('api/activity-requests')->middleware('auth:web')->group(function (): void {
            Route::get('/mine', [ActivityRequestController::class, 'mine']);
            Route::get('/{requestId}', [ActivityRequestController::class, 'show'])->whereNumber('requestId');
            Route::get('/{requestId}/history', [ActivityRequestController::class, 'history'])->whereNumber('requestId');
            Route::middleware('role:Digitador,Gerencial')->group(function (): void {
                Route::get('/', [ActivityRequestController::class, 'index']);
                Route::get('/{requestId}/review-detail', [ActivityRequestController::class, 'reviewDetail'])->whereNumber('requestId');
            });
            Route::get('/decisions/history', [ActivityRequestController::class, 'decisionHistory'])->middleware('role:Gerencial');
        });
    }
}
