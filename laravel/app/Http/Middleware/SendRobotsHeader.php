<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Send `X-Robots-Tag: noindex, nofollow, nosnippet, noarchive` on every
 * response. Single-tenant operator tool — there is nothing here we ever
 * want a search engine to index. Combined with the `Disallow: /` in
 * /robots.txt and the <meta name="robots"> tag in app.blade.php this is
 * a triple defence: well-behaved crawlers honor robots.txt, less-careful
 * ones still see the meta tag once they fetch HTML, and the response
 * header reaches even non-HTML responses (PDFs, JSON exports, etc).
 */
class SendRobotsHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, nosnippet, noarchive');

        return $response;
    }
}
