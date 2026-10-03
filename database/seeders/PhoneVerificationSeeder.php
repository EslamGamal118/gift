<?php

namespace Database\Seeders;

use App\Models\PhoneVerification;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Database\Seeder;

class PhoneVerificationSeeder extends Seeder
{
    /**
     * طلبات توثيق تجريبية مرتبطة بأرقام جوال حسابات فعلية.
     */
    public function run(): void
    {
        // رمز ثابت وصالح لكل حساب تجريبي لتسهيل الدخول أثناء التطوير
        $testPhones = [
            '966500000000', // admin
            '966500000001', // customer
            '966510000000', // store
            '966520000000', // captain
            '966530000000', // shopper
        ];

        foreach ($testPhones as $phone) {
            PhoneVerification::create([
                'country_code' => PhoneNumber::countryCode($phone),
                'phone'        => $phone,
                'otp_code'     => '1234',
                'expires_at'   => now()->addYear(),
            ]);
        }

        $phones = User::query()->inRandomOrder()->limit(10)->pluck('phone');

        foreach ($phones as $phone) {
            // طلب معلّق صالح
            PhoneVerification::factory()->forPhone($phone)->create();

            // طلب تم التحقق منه
            PhoneVerification::factory()->forPhone($phone)->verified()->create();

            // طلب منتهي الصلاحية
            PhoneVerification::factory()->forPhone($phone)->expired()->create();
        }
    }
}
