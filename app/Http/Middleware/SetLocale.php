<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ضبط لغة التطبيق لطلبات الـ API من ترويسة Accept-Language (ar / en).
 * تؤثر على رسائل الاستجابة وعلى الحقول القابلة للترجمة (مثل أسماء التصنيفات وأنواع المركبات).
 */
class SetLocale
{
    /**
     * @var array<int, string>
     */
    protected array $supported = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        // بدون ترويسة Accept-Language نستخدم لغة التطبيق الافتراضية (وليس أول لغة مدعومة)
        $locale = $request->hasHeader('Accept-Language')
            ? $request->getPreferredLanguage($this->supported)
            : null;

        app()->setLocale($locale ?: config('app.locale'));

        // Remember the language on the account so push notifications sent
        // later (outside any request) are rendered in it.
        $user = $request->user('sanctum');
        if ($locale && $user && $user->locale !== $locale) {
            $user->forceFill(['locale' => $locale])->saveQuietly();
        }

        return $next($request);
    }
}
