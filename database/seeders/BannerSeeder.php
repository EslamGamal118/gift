<?php

namespace Database\Seeders;

use App\Models\Banner;
use Illuminate\Database\Seeder;

class BannerSeeder extends Seeder
{
    /**
     * Sample home screen banners with Arabic and English copy.
     */
    public function run(): void
    {
        $banners = [
            [
                'title'       => ['ar' => 'كل مناسبة لها هدية', 'en' => 'Every occasion has a gift'],
                'subtitle'    => ['ar' => 'اختر من آلاف الهدايا المميزة وأرسلها لمن تحب', 'en' => 'Choose from thousands of unique gifts and send them to your loved ones'],
                'button_text' => ['ar' => 'اكتشف الآن', 'en' => 'Discover Now'],
                'image'       => 'banners/banner-1.png',
                'link'        => '/categories',
                'sort_order'  => 1,
                'is_active'   => true,
            ],
            [
                'title'       => ['ar' => 'باقات ورد لكل لحظة', 'en' => 'Flower bouquets for every moment'],
                'subtitle'    => ['ar' => 'ورود طازجة تصل خلال ساعات', 'en' => 'Fresh flowers delivered within hours'],
                'button_text' => ['ar' => 'تسوق الورود', 'en' => 'Shop Flowers'],
                'image'       => 'banners/banner-2.png',
                'link'        => '/categories/3',
                'sort_order'  => 2,
                'is_active'   => true,
            ],
            [
                'title'       => ['ar' => 'حلويات تُسعد القلوب', 'en' => 'Sweets that warm the heart'],
                'subtitle'    => ['ar' => 'تشكيلة مختارة من أفضل المتاجر', 'en' => 'A curated selection from the best stores'],
                'button_text' => ['ar' => 'اطلب الآن', 'en' => 'Order Now'],
                'image'       => 'banners/banner-3.png',
                'link'        => '/categories/2',
                'sort_order'  => 3,
                'is_active'   => true,
            ],
            [
                'title'       => ['ar' => 'عروض العيد', 'en' => 'Eid Offers'],
                'subtitle'    => ['ar' => 'خصومات تصل إلى 30% على هدايا مختارة', 'en' => 'Up to 30% off on selected gifts'],
                'button_text' => ['ar' => 'شاهد العروض', 'en' => 'View Offers'],
                'image'       => 'banners/banner-4.png',
                'link'        => '/offers',
                'sort_order'  => 4,
                'is_active'   => false,
            ],
        ];

        foreach ($banners as $banner) {
            Banner::create($banner);
        }
    }
}
