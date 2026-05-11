<?php

use App\Http\Controllers\Settings\ActivityController;
use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    // F18 — read-only activity / audit log. The page is paginated
    // 50/row and exposes user/action/date/subject filters via the
    // query string so a deep-linked filtered view survives refresh.
    Route::get('settings/activity', [ActivityController::class, 'index'])
        ->name('activity.index');

    // Sanctum personal access tokens. The "create" response renders the
    // plaintext token to the user exactly once (via a session flash);
    // there is no GET endpoint that returns secret values.
    Route::get('settings/api-tokens', [ApiTokenController::class, 'index'])
        ->name('api-tokens.index');
    Route::post('settings/api-tokens', [ApiTokenController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('api-tokens.store');
    Route::delete('settings/api-tokens/{token}', [ApiTokenController::class, 'destroy'])
        ->whereNumber('token')
        ->name('api-tokens.destroy');
});
