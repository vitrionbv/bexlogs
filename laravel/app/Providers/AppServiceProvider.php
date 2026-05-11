<?php

namespace App\Providers;

use ApiPlatform\Laravel\Eloquent\Extension\QueryExtensionInterface;
use App\Api\QueryExtension\OrganizationScopeExtension;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
