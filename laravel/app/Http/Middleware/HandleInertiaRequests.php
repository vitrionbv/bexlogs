<?php

namespace App\Http\Middleware;

use App\Support\JobSummary;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',

            // Lazy props — only computed when explicitly requested via
            // router.reload({ only: [...] }) or on full page loads.
            'jobSummary' => fn () => $user
                ? [
                    'counts' => JobSummary::countsForUser($user),
                    'recent' => JobSummary::recentForUser($user, 5),
                    'sessions_active' => $user->bexSessions()->whereNull('expired_at')->count(),
                    'updated_at' => now()->toIso8601String(),
                ]
                : null,

            // Origin info for the extension auto-link flow.
            'instance' => fn () => [
                'origin' => $request->getSchemeAndHttpHost(),
                'host' => $request->getHost(),
                'name' => config('app.name'),
            ],

            // Extension version requirements consumed by the global
            // `ExtensionUpdatePrompt`. `latestVersion` is the version this
            // Laravel build ships with; `minVersion` is the floor we'll
            // tolerate (defaults to the same — bump via env to force users
            // off older releases).
            'extension' => fn () => [
                'latestVersion' => config('bex.extension_version'),
                'minVersion' => config('bex.extension_min_version', config('bex.extension_version')),
                'downloadUrl' => route('extension.download'),
            ],
        ];
    }
}
