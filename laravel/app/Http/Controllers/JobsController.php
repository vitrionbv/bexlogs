<?php

namespace App\Http\Controllers;

use App\Events\ScrapeJobUpdated;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class JobsController extends Controller
{
    private const ALLOWED_STATUSES = [
        ScrapeJob::STATUS_QUEUED,
        ScrapeJob::STATUS_RUNNING,
        ScrapeJob::STATUS_COMPLETED,
        ScrapeJob::STATUS_FAILED,
        ScrapeJob::STATUS_CANCELLED,
    ];

    public function index(Request $request): Response
    {
        $userId = $request->user()->id;
        $status = $request->string('status')->toString();
        $subscriptionId = $request->string('subscription')->toString();

        $base = ScrapeJob::query()
            ->select('scrape_jobs.*')
            ->join('subscriptions', 'subscriptions.id', '=', 'scrape_jobs.subscription_id')
            ->join('applications', 'applications.id', '=', 'subscriptions.application_id')
            ->join('organizations', 'organizations.id', '=', 'applications.organization_id')
            ->where('organizations.user_id', $userId);

        if (in_array($status, self::ALLOWED_STATUSES, true)) {
            $base->where('scrape_jobs.status', $status);
        }
        if ($subscriptionId !== '') {
            $base->where('scrape_jobs.subscription_id', $subscriptionId);
        }

        $paginated = (clone $base)
            ->with(['subscription:id,name', 'bexSession:id,account_email,environment'])
            ->orderByDesc('scrape_jobs.id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ScrapeJob $j) => [
                'id' => $j->id,
                'subscription_id' => $j->subscription_id,
                'subscription_name' => $j->subscription?->name ?? $j->subscription_id,
                'session_email' => $j->bexSession?->account_email,
                'session_env' => $j->bexSession?->environment,
                'status' => $j->status,
                'attempts' => $j->attempts,
                'created_at' => $j->created_at?->toIso8601String(),
                'started_at' => $j->started_at?->toIso8601String(),
                'completed_at' => $j->completed_at?->toIso8601String(),
                'last_heartbeat_at' => $j->last_heartbeat_at?->toIso8601String(),
                'error' => $j->error,
                'stats' => $j->stats,
                'params' => $j->params,
            ]);

        $statusCounts = (clone $base)
            ->select('scrape_jobs.status', DB::raw('count(*) as c'))
            ->groupBy('scrape_jobs.status')
            ->pluck('c', 'status');

        return Inertia::render('Jobs/Index', [
            'jobs' => $paginated,
            'filters' => [
                'status' => in_array($status, self::ALLOWED_STATUSES, true) ? $status : '',
                'subscription' => $subscriptionId,
            ],
            'statusCounts' => array_merge(array_fill_keys(self::ALLOWED_STATUSES, 0), $statusCounts->toArray()),
            'subscriptions' => Subscription::query()
                ->whereExists(function ($q) use ($userId) {
                    $q->from('applications')
                        ->whereColumn('applications.id', 'subscriptions.application_id')
                        ->whereExists(function ($q) use ($userId) {
                            $q->from('organizations')
                                ->whereColumn('organizations.id', 'applications.organization_id')
                                ->where('organizations.user_id', $userId);
                        });
                })
                ->orderBy('name')
                ->get(['id', 'name'])
                ->all(),
        ]);
    }

    public function retry(Request $request, ScrapeJob $job)
    {
        $this->authorizeJob($request, $job);

        if (! in_array($job->status, [ScrapeJob::STATUS_FAILED, ScrapeJob::STATUS_CANCELLED], true)) {
            return back()->with('error', 'Only failed or cancelled jobs can be retried.');
        }

        $job->forceFill([
            'status' => ScrapeJob::STATUS_QUEUED,
            'started_at' => null,
            'completed_at' => null,
            'last_heartbeat_at' => null,
            'error' => null,
        ])->save();

        broadcast(ScrapeJobUpdated::fromJob($job->fresh()));

        return back()->with('success', "Job #{$job->id} requeued.");
    }

    public function cancel(Request $request, ScrapeJob $job)
    {
        $this->authorizeJob($request, $job);

        if (! in_array($job->status, [ScrapeJob::STATUS_QUEUED, ScrapeJob::STATUS_RUNNING], true)) {
            return back()->with('error', 'Only queued or running jobs can be cancelled.');
        }

        $job->forceFill([
            'status' => ScrapeJob::STATUS_CANCELLED,
            'completed_at' => now(),
        ])->save();

        broadcast(ScrapeJobUpdated::fromJob($job->fresh()));

        return back()->with('success', "Job #{$job->id} cancelled.");
    }

    public function destroy(Request $request, ScrapeJob $job)
    {
        $this->authorizeJob($request, $job);

        $broadcast = ScrapeJobUpdated::fromJob($job);
        $job->delete();
        broadcast($broadcast);

        return back()->with('success', "Job #{$job->id} deleted.");
    }

    public function purge(Request $request): RedirectResponse
    {
        $userId = $request->user()->id;

        $deleted = DB::transaction(function () use ($userId) {
            $ids = DB::table('scrape_jobs')
                ->join('subscriptions', 'subscriptions.id', '=', 'scrape_jobs.subscription_id')
                ->join('applications', 'applications.id', '=', 'subscriptions.application_id')
                ->join('organizations', 'organizations.id', '=', 'applications.organization_id')
                ->where('organizations.user_id', $userId)
                ->whereNotIn('scrape_jobs.status', [
                    ScrapeJob::STATUS_QUEUED,
                    ScrapeJob::STATUS_RUNNING,
                ])
                ->pluck('scrape_jobs.id');

            if ($ids->isEmpty()) {
                return 0;
            }

            $count = DB::table('scrape_jobs')->whereIn('id', $ids)->delete();

            // Reset the sequence so the next insert is contiguous, but only
            // when it can't collide with an in-flight queued/running row.
            $remainingMax = (int) DB::table('scrape_jobs')->max('id');
            if ($remainingMax === 0) {
                DB::statement('ALTER SEQUENCE scrape_jobs_id_seq RESTART WITH 1');
            } else {
                DB::statement("SELECT setval('scrape_jobs_id_seq', {$remainingMax}, true)");
            }

            return $count;
        });

        return back()->with('status', "purged-{$deleted}-jobs");
    }

    private function authorizeJob(Request $request, ScrapeJob $job): void
    {
        $owns = DB::table('subscriptions')
            ->join('applications', 'applications.id', '=', 'subscriptions.application_id')
            ->join('organizations', 'organizations.id', '=', 'applications.organization_id')
            ->where('subscriptions.id', $job->subscription_id)
            ->where('organizations.user_id', $request->user()->id)
            ->exists();

        abort_unless($owns, 403);
    }
}
