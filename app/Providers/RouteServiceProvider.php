<?php

namespace App\Providers;

use App\Support\PhoneNumber;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * رقم الجوال الموحَّد كما يصل في الطلب (mobile أو مرادفه phone)،
     * قبل مرحلة FormRequest — يُستخدم مفتاحًا لحدود المعدل.
     */
    protected function otpPhoneKey(Request $request): string
    {
        $mobile = $request->input('mobile') ?: $request->input('phone');

        return PhoneNumber::normalize(is_string($mobile) ? $mobile : null);
    }

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // حماية نقاط رمز التحقق من الإساءة: حد لكل IP وحد لكل رقم جوال
        // (الرقم يُقرأ من mobile أو من مرادفه phone قبل مرحلة التحقق من الطلب)
        // "Claim a gift" codes are 6 digits: few guesses per account and per device
        RateLimiter::for('gift-claim', function (Request $request) {
            return [
                Limit::perMinute(5)->by('gift-claim:user:'.($request->user()?->id ?: $request->ip())),
                Limit::perDay(20)->by('gift-claim:user-day:'.($request->user()?->id ?: $request->ip())),
                Limit::perDay(50)->by('gift-claim:ip-day:'.$request->ip()),
            ];
        });

        // "Contact us" form: per device, against spam
        RateLimiter::for('contact-form', function (Request $request) {
            return [
                Limit::perMinute(3)->by('contact:ip:'.$request->ip()),
                Limit::perDay(20)->by('contact:ip-day:'.$request->ip()),
            ];
        });

        RateLimiter::for('otp-send', function (Request $request) {
            return [
                Limit::perMinute(10)->by('otp-send:ip:'.$request->ip()),
                Limit::perHour(20)->by('otp-send:phone:'.$this->otpPhoneKey($request)),
            ];
        });

        RateLimiter::for('otp-verify', function (Request $request) {
            return [
                Limit::perMinute(20)->by('otp-verify:ip:'.$request->ip()),
                Limit::perMinute(10)->by('otp-verify:phone:'.$this->otpPhoneKey($request)),
            ];
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
