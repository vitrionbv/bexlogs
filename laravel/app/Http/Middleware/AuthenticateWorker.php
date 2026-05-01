<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('bex.worker_api_token', '');

        if ($expected === '') {
            abort(503, 'WORKER_API_TOKEN is not configured.');
        }

        $supplied = $request->bearerToken() ?? '';

        if (! hash_equals($expected, $supplied)) {
            abort(401, 'Invalid worker token.');
        }

        return $next($request);
    }
}
