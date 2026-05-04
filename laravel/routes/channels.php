<?php

use App\Models\Page;
use App\Models\ScrapeJob;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

/**
 * Per-user firehose: scrape job lifecycle events, sidebar refreshes,
 * page-touched signals for the Logs index, BexSession relink events
 * from the extension pairing endpoint. Single-tenant, so authorisation
 * is just "is this you?".
 */
Broadcast::channel('user.{userId}', function (User $user, int $userId) {
    return $user->id === $userId;
});

/**
 * Per-page channel: live log batch announcements for Logs/Show. Channel
 * name kept as `page.{pageId}` for back-compat with the realtime worker;
 * only the Inertia surface was renamed to "Logs". Anyone subscribing must
 * own the underlying subscription via subscription -> application ->
 * organization -> user.
 */
Broadcast::channel('page.{pageId}', function (User $user, int $pageId) {
    $page = Page::find($pageId);
    if (! $page) {
        return false;
    }

    return DB::table('subscriptions')
        ->join('applications', 'applications.id', '=', 'subscriptions.application_id')
        ->join('organizations', 'organizations.id', '=', 'applications.organization_id')
        ->where('subscriptions.id', $page->subscription_id)
        ->where('organizations.user_id', $user->id)
        ->exists();
});

/**
 * Per-job channel (used rarely; user.{userId} is the firehose).
 */
Broadcast::channel('job.{jobId}', function (User $user, int $jobId) {
    $job = ScrapeJob::find($jobId);
    if (! $job) {
        return false;
    }

    return DB::table('subscriptions')
        ->join('applications', 'applications.id', '=', 'subscriptions.application_id')
        ->join('organizations', 'organizations.id', '=', 'applications.organization_id')
        ->where('subscriptions.id', $job->subscription_id)
        ->where('organizations.user_id', $user->id)
        ->exists();
});

/**
 * Live host metrics (CPU / memory / disk / load) for the operator dashboard.
 * Admin-only — non-admins never see host vitals even on a shared install.
 */
Broadcast::channel('server-stats', function (User $user) {
    return (bool) $user->is_admin;
});
