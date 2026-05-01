<?php

namespace App\Http\Controllers;

use App\Models\BexSession;
use App\Models\Page as LogPage;
use App\Services\ServerMetrics;
use App\Support\JobSummary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, ServerMetrics $metrics): Response
    {
        $user = $request->user();

        $sessions = $user->bexSessions()
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(fn (BexSession $s) => [
                'id' => $s->id,
                'environment' => $s->environment,
                'account_email' => $s->account_email,
                'account_name' => $s->account_name,
                'captured_at' => $s->captured_at?->toIso8601String(),
                'last_validated_at' => $s->last_validated_at?->toIso8601String(),
                'expired_at' => $s->expired_at?->toIso8601String(),
            ])
            ->all();

        $pages = LogPage::query()
            ->whereExists(function ($q) use ($user) {
                $q->from('organizations')
                    ->whereColumn('organizations.id', 'pages.organization_id')
                    ->where('organizations.user_id', $user->id);
            })
            ->with('subscription:id,name,environment,last_scraped_at,auto_scrape')
            ->withCount('logMessages')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get()
            ->map(fn (LogPage $p) => [
                'id' => $p->id,
                'subscription_id' => $p->subscription_id,
                'subscription_name' => $p->subscription?->name ?? $p->subscription_id,
                'environment' => $p->subscription?->environment,
                'auto_scrape' => (bool) ($p->subscription?->auto_scrape),
                'logs_count' => $p->log_messages_count,
                'last_scraped_at' => $p->subscription?->last_scraped_at?->toIso8601String(),
            ])
            ->all();

        // Initial server vitals snapshot for the admin section. Sent only
        // once on page load; live updates after that arrive over the
        // private-server-stats Reverb channel.
        $serverStats = $user->is_admin ? $metrics->snapshot() : null;

        return Inertia::render('Dashboard', [
            'summary' => JobSummary::dashboardForUser($user),
            'sessions' => $sessions,
            'pages' => $pages,
            'serverStats' => $serverStats,
        ]);
    }
}
