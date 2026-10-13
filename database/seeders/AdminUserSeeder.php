<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * حساب المدير الثابت للدخول إلى لوحة التحكم (/admin) أثناء التطوير.
     * آمن للتشغيل أكثر من مرة: يحدّث الحساب إن كان موجودًا.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@gift.test', 'user_type' => User::TYPE_ADMIN],
            [
                'name' => 'مدير النظام',
                'phone' => '966500000000',
                'password' => 'password',
                'status' => 'active',
                'phone_verified_at' => now(),
            ]
        );
    }
}
