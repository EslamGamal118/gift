<?php

namespace App\Support;

/**
 * توحيد صيغة رقم الجوال إلى الصيغة الدولية بدون علامة (+).
 *
 * أمثلة للمدخلات المقبولة (كلها تُحوَّل إلى 9665XXXXXXXX):
 *   05XXXXXXXX، 5XXXXXXXX، +9665XXXXXXXX، 009665XXXXXXXX، 9665XXXXXXXX
 */
class PhoneNumber
{
    public const DEFAULT_COUNTRY_CODE = '966';

    /**
     * مفاتيح الدول المدعومة (تُستخدم لاستخراج مفتاح الدولة من رقم دولي).
     *
     * @var array<int, string>
     */
    public const COUNTRY_CODES = ['966', '971', '965', '973', '968', '974', '962', '20'];

    public static function normalize(?string $phone, string $countryCode = self::DEFAULT_COUNTRY_CODE): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        // 00966XXXXXXXXX -> 966XXXXXXXXX
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // 05XXXXXXXX -> 5XXXXXXXX
        if (str_starts_with($digits, '0')) {
            $digits = ltrim($digits, '0');
        }

        // 5XXXXXXXX -> 9665XXXXXXXX
        if (! str_starts_with($digits, $countryCode)) {
            $digits = $countryCode.$digits;
        }

        return $digits;
    }

    /**
     * استخراج مفتاح الدولة من الرقم كما أدخله المستخدم.
     * الصيغ المحلية (05XXXXXXXX / 5XXXXXXXX) تعيد المفتاح الافتراضي.
     */
    public static function countryCode(?string $phone, string $default = self::DEFAULT_COUNTRY_CODE): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        // 00966XXXXXXXXX -> 966XXXXXXXXX
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        foreach (self::COUNTRY_CODES as $code) {
            if (str_starts_with($digits, $code)) {
                return $code;
            }
        }

        return $default;
    }

    /**
     * توحيد مفتاح الدولة القادم من الطلب (+966 / 00966 / 966 -> 966).
     * القيم الفارغة تعيد null حتى تتكفّل قواعد التحقق بها.
     */
    public static function sanitizeCountryCode(?string $countryCode): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $countryCode) ?? '';

        $digits = ltrim($digits, '0');

        return $digits === '' ? null : $digits;
    }

    /**
     * إخفاء جزء من الرقم للعرض/اللوق: 9665XXXXXXXX -> 9665*****XXX
     */
    public static function mask(string $phone): string
    {
        $len = strlen($phone);

        if ($len <= 7) {
            return str_repeat('*', $len);
        }

        return substr($phone, 0, 4).str_repeat('*', $len - 7).substr($phone, -3);
    }
}
