<?php

use App\Http\Middleware\AuthenticateWorker;
use App\Http\Middleware\EnsureClientIpIsAllowed;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Prepend the IP allowlist to the web + api groups so it runs
        // before auth / throttle / route-model-binding. The `/up`
        // healthcheck route lives outside both groups (registered via
        // `health: '/up'` above) so it is naturally exempt; the
        // middleware itself also short-circuits `/up` and `/api/worker/*`
        // as a belt-and-braces guard.
        $middleware->web(prepend: [
            EnsureClientIpIsAllowed::class,
        ]);

        $middleware->api(prepend: [
            EnsureClientIpIsAllowed::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'worker' => AuthenticateWorker::class,
            'admin' => EnsureUserIsAdmin::class,
            'ip.allowlist' => EnsureClientIpIsAllowed::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
