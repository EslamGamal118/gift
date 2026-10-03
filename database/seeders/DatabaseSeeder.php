<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * الترتيب مهم: الجداول المرجعية أولًا، ثم المستخدمون،
     * ثم البروفايلات التي تعتمد عليها، وأخيرًا البيانات التابعة.
     */
    public function run(): void
    {
        $this->call([
            // 1) جداول مرجعية لا تعتمد على غيرها
            CategorySeeder::class,
            VehicleTypeSeeder::class,
            BannerSeeder::class,
            DeliverySlotSeeder::class,
            LegalPageSeeder::class,
            FaqSeeder::class,

            // 2) المستخدمون (المدير والعملاء)
            UserSeeder::class,

            // 3) البروفايلات — تعتمد على users وعلى الجداول المرجعية
            StoreProfileSeeder::class,   // + store_branches
            CaptainProfileSeeder::class, // يعتمد على vehicle_types
            ShopperProfileSeeder::class, // يعتمد على categories

            // 4) المنتجات — تعتمد على المتاجر المعتمدة والتصنيفات
            ProductSeeder::class,
            StoreReviewSeeder::class,

            // 5) بيانات تابعة تعتمد على أرقام جوال المستخدمين
            PhoneVerificationSeeder::class,

            // 6) طلبات تجريبية مدفوعة للمتجر التجريبي بكل الحالات (orders:demo لمتجر آخر)
            StoreOrderSeeder::class,

            // 7) طلبات المتسوق الشخصي بكل الحالات — تعتمد على العملاء والمتسوقين المعتمدين
            CustomOrderSeeder::class,
        ]);
    }
}
