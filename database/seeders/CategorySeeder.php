<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * التصنيفات الأساسية للتطبيق.
     */
    public function run(): void
    {
        $categories = [
            ['en' => 'Restaurants', 'ar' => 'مطاعم'],
            ['en' => 'Sweets & Desserts', 'ar' => 'حلويات'],
            ['en' => 'Flowers & Gifts', 'ar' => 'ورود وهدايا'],
            ['en' => 'Perfumes', 'ar' => 'عطور'],
            ['en' => 'Electronics', 'ar' => 'إلكترونيات'],
            ['en' => 'Clothing', 'ar' => 'ملابس'],
            ['en' => 'Groceries', 'ar' => 'بقالة'],
            ['en' => 'Pharmacy', 'ar' => 'صيدلية'],
            ['en' => 'Toys', 'ar' => 'ألعاب'],
            ['en' => 'Books & Stationery', 'ar' => 'كتب وقرطاسية'],
        ];

        foreach ($categories as $index => $name) {
            Category::create([
                'name' => $name,
                'image' => 'categories/category-'.($index + 1).'.png',
                'is_active' => true,
            ]);
        }

        // تصنيف خاص: الهدايا الرقمية / الأونلاين (قسم مستقل في الشاشة الرئيسية)
        Category::create([
            'name' => ProductSeeder::ONLINE_GIFTS_CATEGORY,
            'image' => 'categories/online-gifts.png',
            'is_active' => true,
            'is_special' => true,
        ]);
    }
}
