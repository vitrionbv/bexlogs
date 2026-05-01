<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthenticateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExtensionController;
use App\Http\Controllers\JobsController;
use App\Http\Controllers\LogExportController;
use App\Http\Controllers\ManageController;
use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Public endpoint to download the browser extension zip.
Route::get('/extension/download', [ExtensionController::class, 'download'])
    ->name('extension.download');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    // BookingExperts authentication bridge.
    Route::get('authenticate', [AuthenticateController::class, 'index'])
        ->name('authenticate.index');
    Route::post('authenticate/start', [AuthenticateController::class, 'start'])
        ->name('authenticate.start');
    Route::get('authenticate/status', [AuthenticateController::class, 'status'])
        ->name('authenticate.status');
    Route::post('bex-sessions/{bexSession}/validate', [AuthenticateController::class, 'validateNow'])
        ->name('bex-sessions.validate');
    Route::delete('bex-sessions/{bexSession}', [AuthenticateController::class, 'destroy'])
        ->name('bex-sessions.destroy');

    // Background scrape jobs.
    Route::get('jobs', [JobsController::class, 'index'])->name('jobs.index');
    Route::post('jobs/{job}/retry', [JobsController::class, 'retry'])->name('jobs.retry');
    Route::post('jobs/{job}/cancel', [JobsController::class, 'cancel'])->name('jobs.cancel');
    // NOTE: must be registered before the `jobs/{job}` destroy route, otherwise
    // `DELETE /jobs/old` would be captured by route-model-binding and 404.
    Route::delete('jobs/old', [JobsController::class, 'purge'])->name('jobs.purge');
    Route::delete('jobs/{job}', [JobsController::class, 'destroy'])->name('jobs.destroy');

    // Logs = per-subscription log feeds. One row per (org × app × subscription).
    // The underlying model is still `Page` (table `pages`, channel `private-page.{id}`);
    // only the user-facing surface is named "Logs".
    Route::get('logs', [PageController::class, 'index'])->name('logs.index');
    Route::get('logs/{page}', [PageController::class, 'show'])->name('logs.show');
    // The destroy-all-entries endpoint is `messages` (not `logs`) so the URL
    // doesn't read as `/logs/{id}/logs`, which is confusing.
    Route::delete('logs/{page}/messages', [PageController::class, 'destroyLogs'])->name('logs.messages.destroy');
    Route::get('logs/{page}/export', [LogExportController::class, 'export'])->name('logs.export');
    Route::post('logs/{page}/import', [LogExportController::class, 'import'])->name('logs.import');

    // Temporary 301 redirects so any bookmarks / in-flight Inertia visits to
    // the old `/pages*` URLs don't 404. The `{page}` parameter passes through.
    Route::redirect('pages', 'logs', 301);
    Route::redirect('pages/{page}', 'logs/{page}', 301);
    Route::redirect('pages/{page}/logs', 'logs/{page}/messages', 301);
    Route::redirect('pages/{page}/export', 'logs/{page}/export', 301);
    Route::redirect('pages/{page}/import', 'logs/{page}/import', 301);

    // Manage organizations / applications / subscriptions.
    Route::get('manage', [ManageController::class, 'index'])->name('manage.index');
    Route::post('manage/subscriptions', [ManageController::class, 'storeSubscription'])
        ->name('manage.subscriptions.store');
    Route::patch('manage/subscriptions/{subscription}', [ManageController::class, 'updateSubscription'])
        ->name('manage.subscriptions.update');
    Route::delete('manage/subscriptions/{subscription}', [ManageController::class, 'destroySubscription'])
        ->name('manage.subscriptions.destroy');
    Route::post('manage/subscriptions/{subscription}/scrape', [ManageController::class, 'enqueueScrape'])
        ->name('manage.subscriptions.scrape');

    // BookingExperts catalog browsing (used by the "Add Subscription → Browse" tab).
    // The cascade is application → organization → subscription so the user
    // never has to pre-pick an org they may only know by their app's name.
    Route::get('manage/browse/applications', [ManageController::class, 'browseApplications'])
        ->name('manage.browse.applications');
    Route::get('manage/browse/applications/{application}/organizations', [ManageController::class, 'browseOrganizationsForApplication'])
        ->name('manage.browse.organizations-for-application');
    Route::get('manage/browse/applications/{application}/subscriptions', [ManageController::class, 'browseSubscriptionsForApplication'])
        ->name('manage.browse.subscriptions-for-application');

    // Admin-only user management. Single-tenant app — one boolean
    // (`users.is_admin`) gates everything in this group.
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [UserController::class, 'index'])
            ->name('users.index');
        Route::post('users', [UserController::class, 'store'])
            ->name('users.store');
        Route::patch('users/{user}', [UserController::class, 'update'])
            ->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])
            ->name('users.destroy');
        Route::post('users/{user}/password-reset', [UserController::class, 'sendPasswordResetLink'])
            ->name('users.password-reset');
    });
});

require __DIR__.'/settings.php';
