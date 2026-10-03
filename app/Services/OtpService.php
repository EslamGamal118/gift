<?php

namespace App\Services;

use App\Exceptions\OtpException;
use App\Models\PhoneVerification;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * خدمة إنشاء رموز التحقق وإرسالها والتحقق منها.
 *
 * - في بيئات الاختبار (local/staging/testing) يُثبَّت الرمز على otp.testing_code
 *   ولا يُرسَل SMS فعلي.
 * - في الإنتاج يُولَّد رمز عشوائي آمن ويُرسَل عبر 4Jawaly.
 *
 * الخدمة مبنية على رقم الجوال فقط (لا تتطلب وجود مستخدم): تُسجَّل الطلبات في
 * جدول phone_verifications، فيُوثَّق الرقم — ويُحفظ الاسم المصاحب للتسجيل —
 * قبل إنشاء حساب المستخدم.
 */
class OtpService
{
    public function __construct(
        protected ForJawalyProvider $sms,
    ) {
    }

    /**
     * هل نحن في بيئة يُثبَّت فيها الرمز ويُتجاوَز فيها الإرسال الفعلي؟
     */
    public function isTestingEnvironment(): bool
    {
        return app()->environment(config('otp.testing_environments', []));
    }

    public function expiresInMinutes(): int
    {
        return (int) config('otp.expires_in', 5);
    }

    public function resendCooldownSeconds(): int
    {
        return (int) config('otp.resend_cooldown', 60);
    }

    /**
     * إنشاء طلب توثيق جديد لرقم الجوال وتخزينه وإرسال الرمز.
     *
     * @param  string       $action       login | register (للتوثيق والسجلات)
     * @param  string|null  $name         اسم صاحب الحساب (يُخزَّن مع التسجيل ويُستخدم بعد التحقق)
     * @param  string|null  $countryCode  مفتاح الدولة (الافتراضي 966)
     *
     * @throws OtpException
     */
    public function send(
        string $phone,
        string $action = 'login',
        ?string $name = null,
        ?string $countryCode = null,
    ): PhoneVerification {
        $sendKey = $this->sendKey($phone);

        if (RateLimiter::tooManyAttempts($sendKey, 1)) {
            throw OtpException::cooldown(RateLimiter::availableIn($sendKey));
        }

        $code = $this->generateCode();

        $verification = DB::transaction(function () use ($phone, $code, $name, $countryCode) {
            // إبطال أي طلبات سابقة معلّقة لنفس الرقم (رمز واحد صالح في كل وقت)
            PhoneVerification::forPhone($phone)->pending()->update(['expires_at' => now()]);

            return PhoneVerification::create([
                'country_code' => $countryCode ?: PhoneNumber::countryCode($phone),
                'phone'        => $phone,
                'name'         => $name,
                'otp_code'     => $code,
                'expires_at'   => now()->addMinutes($this->expiresInMinutes()),
            ]);
        });

        if ($this->isTestingEnvironment()) {
            Log::info('[OTP] Testing environment - SMS dispatch skipped', [
                'env'    => app()->environment(),
                'phone'  => PhoneNumber::mask($phone),
                'code'   => $code,
                'action' => $action,
            ]);
        } else {
            $sent = $this->sms->send($phone, $this->buildMessage($code));

            if (! $sent) {
                $verification->delete();

                throw OtpException::sendFailed();
            }

            Log::info('[OTP] Code dispatched', [
                'phone'      => PhoneNumber::mask($phone),
                'action'     => $action,
                'expires_at' => $verification->expires_at->toIso8601String(),
            ]);
        }

        RateLimiter::hit($sendKey, $this->resendCooldownSeconds());

        return $verification;
    }

    /**
     * التحقق من صحة الرمز وتوثيق رقم الجوال.
     *
     * الرمز يُستخدم مرة واحدة فقط: بعد نجاح التحقق يُختَم طلب التوثيق عبر تحديث شرطي ذرّي،
     * فلا يمكن لطلبين متزامنين (أو لإعادة إرسال نفس الرمز لاحقًا) النجاح به مرتين.
     * ينطبق ذلك على جميع البيئات — في بيئات الاختبار يكون الرمز المخزَّن 1234 لكنه
     * يبقى مرتبطًا بطلب send-otp ويُستهلك مرة واحدة كذلك.
     *
     * @return PhoneVerification  الطلب الموثَّق (يحمل الاسم المخزَّن عند التسجيل)
     *
     * @throws OtpException
     */
    public function verify(string $phone, string $code): PhoneVerification
    {
        $verifyKey   = $this->verifyKey($phone);
        $maxAttempts = (int) config('otp.max_attempts', 5);

        if (RateLimiter::tooManyAttempts($verifyKey, $maxAttempts)) {
            throw OtpException::tooManyAttempts(RateLimiter::availableIn($verifyKey));
        }

        // آخر طلب توثيق صادر لهذا الرقم (مستهلكًا كان أو لا) حتى نميّز "مستخدم مسبقًا" عن "غير صحيح"
        /** @var PhoneVerification|null $verification */
        $verification = PhoneVerification::forPhone($phone)->latest('id')->first();

        if (! $verification || ! hash_equals($verification->otp_code, $code)) {
            $this->registerFailedAttempt($verifyKey, $phone);

            throw OtpException::invalid();
        }

        if ($verification->isVerified()) {
            throw OtpException::alreadyUsed();
        }

        if ($verification->isExpired()) {
            throw OtpException::expired();
        }

        if (! $this->claim($verification)) {
            // طلب متزامن آخر استهلك الرمز قبلنا
            throw OtpException::alreadyUsed();
        }

        RateLimiter::clear($verifyKey);
        RateLimiter::clear($this->sendKey($phone));

        Log::info('[OTP] Code verified and consumed', [
            'phone'           => PhoneNumber::mask($phone),
            'verification_id' => $verification->id,
        ]);

        return $verification->refresh();
    }

    /**
     * استهلاك الرمز ذرّيًا: يعود true لطلب واحد فقط حتى لو تزامنت عدة طلبات.
     */
    protected function claim(PhoneVerification $verification): bool
    {
        $claimed = PhoneVerification::whereKey($verification->id)
            ->whereNull('verified_at')
            ->update(['verified_at' => now()]);

        return $claimed === 1;
    }

    protected function registerFailedAttempt(string $verifyKey, string $phone): void
    {
        RateLimiter::hit($verifyKey, (int) config('otp.lockout_seconds', 600));

        Log::warning('[OTP] Invalid code attempt', [
            'phone'    => PhoneNumber::mask($phone),
            'attempts' => RateLimiter::attempts($verifyKey),
        ]);
    }

    /**
     * توليد الرمز: ثابت في بيئات الاختبار، عشوائي آمن (4 أو 6 خانات) في الإنتاج.
     */
    protected function generateCode(): string
    {
        if ($this->isTestingEnvironment()) {
            return (string) config('otp.testing_code');
        }

        $length = (int) config('otp.length', 4) === 6 ? 6 : 4;

        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    protected function buildMessage(string $code): string
    {
        return __('messages.otp_sms', [
            'app'     => config('app.name'),
            'code'    => $code,
            'minutes' => $this->expiresInMinutes(),
        ]);
    }

    protected function sendKey(string $phone): string
    {
        return 'otp:send:'.$phone;
    }

    protected function verifyKey(string $phone): string
    {
        return 'otp:verify:'.$phone;
    }
}
