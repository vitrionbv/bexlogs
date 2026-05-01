<?php

use App\Http\Controllers\Api\BexSessionController;
use App\Http\Controllers\Api\WorkerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Public + worker endpoints. See routes/web.php for the user-facing
| Inertia routes.
|
*/

// Browser extension drops cookies + pairing token here. No auth middleware:
// the pairing token is the auth.
Route::post('/bex-sessions', [BexSessionController::class, 'store'])
    ->name('api.bex-sessions.store');

// Worker endpoints (Bearer-token authed).
Route::middleware('worker')->prefix('worker')->name('api.worker.')->group(function () {
    Route::get('jobs/next', [WorkerController::class, 'nextJob'])->name('jobs.next');
    Route::post('jobs/{job}/heartbeat', [WorkerController::class, 'heartbeat'])->name('jobs.heartbeat');
    Route::post('jobs/{job}/batch', [WorkerController::class, 'batch'])->name('jobs.batch');
    Route::post('jobs/{job}/complete', [WorkerController::class, 'complete'])->name('jobs.complete');
    Route::post('jobs/{job}/fail', [WorkerController::class, 'fail'])->name('jobs.fail');
    Route::post('sessions/{session}/expired', [WorkerController::class, 'sessionExpired'])
        ->name('sessions.expired');
});
