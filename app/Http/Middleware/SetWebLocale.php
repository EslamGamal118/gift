<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Website language (ar / en): `?lang=` from the language switcher wins and is
 * remembered in the session, otherwise the session's choice, otherwise the
 * app default (ar). The API uses SetLocale (Accept-Language) instead.
 */
class SetWebLocale
{
    public const SESSION_KEY = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('app.supported_locales', ['ar', 'en']);
        $requested = $request->query('lang');

        if (is_string($requested) && in_array($requested, $supported, true)) {
            $request->session()->put(self::SESSION_KEY, $requested);
        }

        $locale = $request->session()->get(self::SESSION_KEY);

        app()->setLocale(in_array($locale, $supported, true) ? $locale : config('app.locale'));

        return $next($request);
    }
}
