<?php

namespace App\Http\Middleware;

use App\Support\Localization\PortalLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Client portal requests are answered in the client's language: the one
 * saved on their record once signed in, otherwise the one the portal asks
 * for (the sign-in and password screens).
 */
class SetPortalLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(PortalLocale::normalize($request->user('client')?->locale ?? $request->header('X-Locale')));

        return $next($request);
    }
}
