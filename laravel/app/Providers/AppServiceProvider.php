<?php

namespace App\Providers;

use ApiPlatform\Laravel\Eloquent\Extension\QueryExtensionInterface;
use App\Api\QueryExtension\OrganizationScopeExtension;
use App\Events\LogBatchInserted;
use App\Listeners\AlertOnLogBatchListener;
use App\Models\BexSession;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Observers\AuditBexSessionObserver;
use App\Observers\AuditScrapeJobObserver;
use App\Observers\AuditSubscriptionObserver;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // AuditLogger is bound as a singleton so the in-process dedup
        // map (`$seen`) is shared between the controller writes and
        // the fallback observers within the same request. Without the
        // singleton each observer would resolve a fresh instance and
        // the explicit `suppressNext()` calls would be no-ops.
        $this->app->singleton(AuditLogger::class);

        // Tag our org-scoping query extension so api-platform's Eloquent
        // CollectionProvider/ItemProvider pick it up automatically (the
        // package consumes `app()->tagged(QueryExtensionInterface::class)`
        // when constructing both providers). This is the single
        // enforcement point that makes every #[ApiResource] read query
        // org-scoped to the authenticated user — see
        // app/Api/QueryExtension/OrganizationScopeExtension.php for the
        // per-model rules.
        $this->app->singleton(OrganizationScopeExtension::class);
        $this->app->tag([OrganizationScopeExtension::class], QueryExtensionInterface::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerAuditObservers();
        $this->wireAlertListeners();
    }

    /**
     * Wire the audit observers onto the models whose lifecycle events
     * should land in `audit_logs`. The controllers also call
     * `AuditLogger::record()` directly with hand-curated payloads; the
     * observer is the fallback for routes/commands the controllers
     * don't cover. See {@see AuditLogger::$seen} for the dedup
     * mechanism that keeps both layers from double-writing.
     */
    protected function registerAuditObservers(): void
    {
        Subscription::observe(AuditSubscriptionObserver::class);
        BexSession::observe(AuditBexSessionObserver::class);
        ScrapeJob::observe(AuditScrapeJobObserver::class);
    }

    /**
     * Subscribe the alerting layer (B4) to LogBatchInserted. Registered
     * here instead of via Laravel's auto-discovery because the Laravel
     * 11 default opt-out leaves ->withEvents() unset, and the listener's
     * one-line registration is cheaper than wiring a full
     * EventServiceProvider just for a single binding. The listener
     * itself implements ShouldQueue so the inline broadcast event
     * (LogBatchInserted is ShouldBroadcastNow for ordering reasons)
     * doesn't pay the alert-evaluation latency on the worker request.
     */
    protected function wireAlertListeners(): void
    {
        Event::listen(LogBatchInserted::class, AlertOnLogBatchListener::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
