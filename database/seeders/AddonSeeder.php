<?php

namespace Database\Seeders;

use App\Models\Addon;
use App\Models\Category;
use App\Models\Product;
use App\Models\StoreProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * إضافات واقعية (تغليف، بطاقة إهداء، جبنة إضافية...) لكل متجر معتمد لديه منتجات.
 *
 * الإضافة تظهر على المنتج فقط إن كانت لنفس المتجر ومربوطة بتصنيف المنتج
 * (Product::availableAddons)، لذا تُربط كل إضافة بتصنيفات منتجات المتجر المطابقة.
 * قابل لإعادة التشغيل: المتاجر التي لديها إضافات تُتجاوز.
 */
class AddonSeeder extends Seeder
{
    /**
     * يُطابق كل الكتالوجات.
     */
    protected const ANY = '*';

    public function run(): void
    {
        $categories = Category::query()->get()->keyBy(fn (Category $c) => $c->getTranslation('name', 'en'));

        $stores = StoreProfile::query()
            ->where('status', 'approved')
            ->orderBy('id')
            ->get();

        if ($stores->isEmpty()) {
            $this->command?->warn('AddonSeeder: no approved stores found, run StoreProfileSeeder first.');

            return;
        }

        $created = 0;

        foreach ($stores as $store) {
            if (Addon::query()->forStore($store->user_id)->exists()) {
                continue;
            }

            // تصنيفات منتجات المتجر (en name => id)
            $productCategoryIds = Product::query()->where('store_id', $store->user_id)->distinct()->pluck('category_id');
            $storeCategories    = $categories->filter(fn (Category $c) => $productCategoryIds->contains($c->id))->map->id;

            if ($storeCategories->isEmpty()) {
                continue;
            }

            DB::transaction(function () use ($store, $storeCategories, &$created) {
                foreach ($this->catalog() as $item) {
                    $categoryIds = in_array(self::ANY, $item['categories'], true)
                        ? $storeCategories->values()
                        : $storeCategories->only($item['categories'])->values();

                    if ($categoryIds->isEmpty()) {
                        continue;
                    }

                    $addon = Addon::create([
                        'store_id'       => $store->user_id,
                        'name'           => $item['name'],
                        'price'          => $item['price'],
                        'stock_quantity' => $item['stock_quantity'],
                        'image'          => null,
                        'is_active'      => $item['is_active'] ?? true,
                    ]);

                    $addon->categories()->attach($categoryIds->all());
                    $created++;
                }
            });
        }

        $this->command?->info("AddonSeeder: created {$created} addons.");
    }

    /**
     * @return list<array{name: string, price: float, stock_quantity: int, categories: list<string>, is_active?: bool}>
     */
    protected function catalog(): array
    {
        return [
            // إضافات الإهداء — تصلح لكل التصنيفات
            ['name' => 'تغليف هدية فاخر',          'price' => 15.00, 'stock_quantity' => 100, 'categories' => [self::ANY]],
            ['name' => 'بطاقة إهداء مكتوبة',        'price' => 5.00,  'stock_quantity' => 200, 'categories' => [self::ANY]],
            ['name' => 'توصيل سريع خلال ساعة',     'price' => 25.00, 'stock_quantity' => 50,  'categories' => [self::ANY]],

            // إضافات خاصة بتصنيفات معينة
            ['name' => 'جبنة إضافية',              'price' => 6.00,  'stock_quantity' => 80,  'categories' => ['Restaurants']],
            ['name' => 'ترقية للحجم الكبير',        'price' => 12.00, 'stock_quantity' => 80,  'categories' => ['Restaurants', 'Sweets & Desserts']],
            ['name' => 'شموع عيد ميلاد',           'price' => 8.00,  'stock_quantity' => 60,  'categories' => ['Sweets & Desserts', 'Flowers & Gifts']],
            ['name' => 'بالونات هيليوم (5 قطع)',    'price' => 35.00, 'stock_quantity' => 30,  'categories' => ['Flowers & Gifts', 'Toys', 'Sweets & Desserts']],
            ['name' => 'علبة شوكولاتة صغيرة',      'price' => 29.00, 'stock_quantity' => 40,  'categories' => ['Flowers & Gifts', 'Perfumes']],
            ['name' => 'دب محشو صغير',             'price' => 45.00, 'stock_quantity' => 20,  'categories' => ['Flowers & Gifts', 'Toys']],
            ['name' => 'عينة عطر 10 مل',           'price' => 19.00, 'stock_quantity' => 0,   'categories' => ['Perfumes']],             // نفدت الكمية
            ['name' => 'تمديد الضمان سنة',          'price' => 49.00, 'stock_quantity' => 100, 'categories' => ['Electronics']],
            ['name' => 'تطريز الاسم',              'price' => 30.00, 'stock_quantity' => 25,  'categories' => ['Clothing'], 'is_active' => false], // غير مفعّلة
        ];
    }
}
