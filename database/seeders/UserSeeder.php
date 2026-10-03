<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * حساب المدير + عملاء التطبيق.
     *
     * ملاحظة: حسابات المتاجر/الكباتن/المتسوقين تُنشأ ضمن السيدرات
     * الخاصة بكل بروفايل حتى تبقى مرتبطة ببروفايلاتها.
     */
    public function run(): void
    {
        // حساب المدير الثابت للدخول أثناء التطوير
        User::create([
            'name' => 'مدير النظام',
            'phone' => '966500000000',
            'email' => 'admin@gift.test',
            'user_type' => 'admin',
            'status' => 'active',
            'phone_verified_at' => now(),
        ]);

        // عميل ثابت للاختبار
        User::create([
            'name' => 'عميل تجريبي',
            'phone' => '966500000001',
            'email' => 'customer@gift.test',
            'user_type' => 'customer',
            'status' => 'active',
            'phone_verified_at' => now(),
        ]);

        // عملاء عشوائيون
        User::factory()->count(20)->customer()->create();

        // عملاء لم يوثّقوا أرقامهم بعد
        User::factory()->count(3)->customer()->unverified()->create();

        // عميل محظور
        User::factory()->customer()->blocked()->create();
    }
}
