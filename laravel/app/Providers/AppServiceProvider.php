<?php

namespace App\Providers;

use App\Events\LogBatchInserted;
use App\Listeners\AlertOnLogBatchListener;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->wireAlertListeners();
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
