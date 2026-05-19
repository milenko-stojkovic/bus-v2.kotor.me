<?php

namespace App\Http\Middleware;

use App\Helpers\LocaleHelper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * UI language: explicit session choice (CG/EN link) wins; else auth -> users.lang;
 * guests -> Accept-Language (cg/hr/sr/bs -> cg, else en).
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && $request->session()->has('locale') && LocaleHelper::isValid((string) $request->session()->get('locale'))) {
            $locale = (string) $request->session()->get('locale');
        } elseif ($request->user() && $request->user()->lang) {
            $locale = $request->user()->lang;
        } else {
            $locale = LocaleHelper::fromAcceptLanguage($request->header('Accept-Language'));
        }

        if (LocaleHelper::isValid($locale)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
