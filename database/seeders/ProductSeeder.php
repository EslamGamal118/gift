<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\StoreProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * منتجات واقعية لكل متجر معتمد، مربوطة بالتصنيفات الأساسية.
 *
 * كل متجر يحصل على مجموعة من منتجات تصنيفه (وإن كان تصنيفه من التصنيفات
 * العشوائية يُختار له كتالوج حقيقي)، مع تمييز بعض المنتجات والمتاجر لتظهر
 * في أقسام الشاشة الرئيسية. قابل لإعادة التشغيل: المتاجر التي لديها منتجات تُتجاوز.
 */
class ProductSeeder extends Seeder
{
    public const ONLINE_GIFTS_CATEGORY = ['en' => 'Online Gifts', 'ar' => 'هدايا أونلاين'];

    /**
     * عدد المنتجات لكل متجر (تُختار عشوائيًا من كتالوج تصنيفه).
     */
    protected int $minPerStore = 6;
    protected int $maxPerStore = 10;

    public function run(): void
    {
        $categories = Category::query()->get()->keyBy(fn (Category $c) => $c->getTranslation('name', 'en'));
        $catalog    = $this->catalog();

        // التصنيف الخاص بالهدايا الرقمية (قد لا يكون موجودًا في قواعد بيانات أُنشئت قبل إضافته)
        $online = $categories[self::ONLINE_GIFTS_CATEGORY['en']] ?? Category::create([
            'name'       => self::ONLINE_GIFTS_CATEGORY,
            'image'      => 'categories/online-gifts.png',
            'is_active'  => true,
            'is_special' => true,
        ]);

        $stores = StoreProfile::query()
            ->where('status', 'approved')
            ->with('category')
            ->orderBy('id')
            ->get();

        if ($stores->isEmpty()) {
            $this->command?->warn('ProductSeeder: no approved stores found, run StoreProfileSeeder first.');

            return;
        }

        $created = 0;

        foreach ($stores as $index => $store) {
            if (Product::query()->where('store_id', $store->user_id)->exists()) {
                continue;
            }

            $categoryName = $store->category?->getTranslation('name', 'en');

            // متجر بتصنيف بلا كتالوج (تصنيف عشوائي من الـ factory) -> كتالوج حقيقي بالتناوب
            if (! isset($catalog[$categoryName], $categories[$categoryName])) {
                $categoryName = array_keys($catalog)[$index % count($catalog)];
            }

            $category = $categories[$categoryName] ?? null;

            if (! $category) {
                continue;
            }

            $created += $this->seedStoreProducts($store, $category, $catalog[$categoryName]);

            // أول ثلاثة متاجر تُميَّز في الشاشة الرئيسية
            if ($index < 3 && ! $store->is_featured) {
                $store->forceFill(['is_featured' => true])->save();
            }
        }

        $created += $this->seedOnlineGifts($stores, $online);

        $this->command?->info("ProductSeeder: {$created} products created.");
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function seedStoreProducts(StoreProfile $store, Category $category, array $items): int
    {
        $picked = collect($items)->shuffle()->take(fake()->numberBetween($this->minPerStore, min($this->maxPerStore, count($items))));

        foreach ($picked->values() as $i => $item) {
            $this->createProduct($store, $category, $item, [
                // منتج أو اثنان مميزان لكل متجر
                'is_featured' => $i < 2 && fake()->boolean(70),
                // بعض المنتجات نفدت من المخزون
                'stock_quantity' => fake()->boolean(10) ? 0 : fake()->numberBetween(5, 80),
            ]);
        }

        return $picked->count();
    }

    /**
     * الهدايا الرقمية تُباع من عدد محدود من المتاجر (كمياتها = المقاعد المتاحة).
     *
     * @param  Collection<int, StoreProfile>  $stores
     */
    protected function seedOnlineGifts(Collection $stores, Category $category): int
    {
        if (Product::query()->where('category_id', $category->id)->exists()) {
            return 0;
        }

        $sellers = $stores->take(3);
        $items   = $this->onlineGifts();
        $created = 0;

        foreach ($items as $i => $item) {
            $this->createProduct($sellers[$i % $sellers->count()], $category, $item, [
                'is_featured'      => $i < 3,
                'stock_quantity'   => fake()->numberBetween(1, 25),
                'preparation_time' => 5,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $overrides
     */
    protected function createProduct(StoreProfile $store, Category $category, array $item, array $overrides = []): Product
    {
        $expiryDays = $item['expiry_days'] ?? null;

        return Product::create($overrides + [
            'store_id'         => $store->user_id,
            'category_id'      => $category->id,
            'name'             => $item['name'],
            'description'      => $item['description'],
            'price'            => $item['price'],
            'image'            => 'products/'.$item['slug'].'.jpg',
            'preparation_time' => $item['preparation_time'] ?? $store->preparation_time ?? 20,
            'expiry_date'      => $expiryDays ? now()->addDays($expiryDays)->toDateString() : null,
            'rating_avg'       => fake()->randomFloat(2, 3.5, 5),
            'rating_count'     => fake()->numberBetween(0, 320),
            'stock_quantity'   => fake()->numberBetween(5, 80),
            'is_featured'      => false,
        ]);
    }

    /**
     * الهدايا الرقمية / الأونلاين (التصنيف الخاص في الشاشة الرئيسية).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function onlineGifts(): array
    {
        return [
            ['slug' => 'playstation-plus-3m',  'name' => 'اشتراك PlayStation Plus - 3 أشهر',   'price' => 119.00, 'description' => 'كود رقمي يُرسل فورًا للاستمتاع بالألعاب أونلاين لثلاثة أشهر.'],
            ['slug' => 'xbox-game-pass-1m',    'name' => 'Xbox Game Pass Ultimate - شهر',      'price' => 59.00,  'description' => 'وصول لمئات الألعاب على الجهاز والحاسب مع اللعب السحابي.'],
            ['slug' => 'itunes-100',           'name' => 'بطاقة iTunes بقيمة 100 ريال',        'price' => 100.00, 'description' => 'بطاقة هدايا رقمية للمتجر السعودي، تصل خلال دقائق.'],
            ['slug' => 'google-play-50',       'name' => 'بطاقة Google Play بقيمة 50 ريال',    'price' => 50.00,  'description' => 'رصيد رقمي للتطبيقات والألعاب والكتب على متجر Google Play.'],
            ['slug' => 'steam-200',            'name' => 'بطاقة Steam بقيمة 200 ريال',         'price' => 200.00, 'description' => 'رصيد لمحفظة Steam لشراء الألعاب والإضافات.'],
            ['slug' => 'spotify-premium-3m',   'name' => 'Spotify Premium - 3 أشهر',           'price' => 65.00,  'description' => 'استماع بدون إعلانات وتحميل للاستماع دون إنترنت.'],
            ['slug' => 'netflix-gift-150',     'name' => 'بطاقة هدايا Netflix بقيمة 150 ريال', 'price' => 150.00, 'description' => 'اشتراك للمشاهدة يُضاف للحساب مباشرة عبر كود رقمي.'],
            ['slug' => 'shahid-vip-1y',        'name' => 'اشتراك شاهد VIP - سنة',              'price' => 249.00, 'description' => 'المسلسلات والأفلام العربية والقنوات المباشرة لمدة عام كامل.'],
            ['slug' => 'pubg-uc-1800',         'name' => 'PUBG Mobile - 1800 UC',              'price' => 109.00, 'description' => 'شحن مباشر لحساب اللاعب خلال دقائق من تأكيد الطلب.'],
        ];
    }

    /**
     * كتالوج المنتجات لكل تصنيف أساسي (المفتاح = الاسم الإنجليزي للتصنيف).
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function catalog(): array
    {
        return [
            'Restaurants' => [
                ['slug' => 'kabsa-lamb',        'name' => 'كبسة لحم',                 'price' => 65.00,  'preparation_time' => 35, 'description' => 'أرز بسمتي مع لحم غنم طري وبهارات الكبسة الأصيلة.'],
                ['slug' => 'mandi-chicken',     'name' => 'مندي دجاج',                'price' => 42.00,  'preparation_time' => 30, 'description' => 'نصف دجاجة مدخنة على طريقة المندي مع أرز وصلصة الدقوس.'],
                ['slug' => 'mixed-grill',       'name' => 'مشاوي مشكلة',              'price' => 89.00,  'preparation_time' => 40, 'description' => 'شيش طاووق وكفتة وكباب مع خبز وحمّص وسلطة.'],
                ['slug' => 'beef-burger',       'name' => 'برجر لحم أنجوس',            'price' => 38.00,  'preparation_time' => 20, 'description' => 'قطعة لحم أنجوس 180 غ مع جبنة شيدر وصلصة خاصة وبطاطس.'],
                ['slug' => 'margherita-pizza',  'name' => 'بيتزا مارغريتا',           'price' => 34.00,  'preparation_time' => 25, 'description' => 'عجينة رقيقة مع صلصة طماطم وجبنة موزاريلا وريحان طازج.'],
                ['slug' => 'shrimp-pasta',      'name' => 'باستا بالروبيان',           'price' => 56.00,  'preparation_time' => 25, 'description' => 'فيتوتشيني بصلصة الكريمة والثوم مع روبيان مشوي.'],
                ['slug' => 'family-feast',      'name' => 'عرض العائلة',              'price' => 179.00, 'preparation_time' => 45, 'description' => 'يكفي 4-5 أشخاص: كبسة، مندي، مشاوي، سلطات ومشروبات.'],
                ['slug' => 'falafel-box',       'name' => 'صندوق فلافل وحمّص',         'price' => 24.00,  'preparation_time' => 15, 'description' => '12 قطعة فلافل مع حمّص وخبز عربي ومخللات.'],
            ],
            'Sweets & Desserts' => [
                ['slug' => 'chocolate-cake',    'name' => 'كيكة شوكولاتة فاخرة',       'price' => 145.00, 'preparation_time' => 60, 'description' => 'كيكة بلجيكية ثلاث طبقات بغاناش الشوكولاتة، تكفي 10 أشخاص.'],
                ['slug' => 'red-velvet-cake',   'name' => 'كيكة ريد فيلفت',            'price' => 130.00, 'preparation_time' => 60, 'description' => 'طبقات ريد فيلفت مع كريمة الجبن الطازجة وتزيين مناسبات.'],
                ['slug' => 'kunafa-cream',      'name' => 'كنافة بالقشطة',             'price' => 48.00,  'preparation_time' => 25, 'description' => 'كنافة نابلسية بالقشطة الطازجة والقطر، صينية متوسطة.'],
                ['slug' => 'luqaimat',          'name' => 'لقيمات بالعسل',              'price' => 22.00,  'preparation_time' => 15, 'description' => 'لقيمات مقرمشة مع دبس التمر والسمسم، 30 حبة.'],
                ['slug' => 'dates-box',         'name' => 'علبة تمور فاخرة محشوة',     'price' => 95.00,  'preparation_time' => 10, 'expiry_days' => 90, 'description' => 'تمور سكري ومجدول محشوة بالمكسرات في علبة هدايا، 500 غ.'],
                ['slug' => 'macarons-12',       'name' => 'ماكرون فرنسي - 12 قطعة',     'price' => 68.00,  'preparation_time' => 15, 'expiry_days' => 7, 'description' => 'تشكيلة نكهات: فستق، توت، كراميل، شوكولاتة.'],
                ['slug' => 'cheesecake-slice',  'name' => 'تشيز كيك لوتس - كاملة',      'price' => 120.00, 'preparation_time' => 45, 'description' => 'تشيز كيك نيويورك بطبقة زبدة اللوتس، 8 قطع.'],
                ['slug' => 'cupcakes-6',        'name' => 'كب كيك مزيّن - 6 قطع',       'price' => 54.00,  'preparation_time' => 30, 'description' => 'كب كيك فانيليا وشوكولاتة مع كريمة الزبدة، تزيين حسب المناسبة.'],
            ],
            'Flowers & Gifts' => [
                ['slug' => 'red-roses-25',      'name' => 'باقة ورد جوري أحمر - 25 وردة', 'price' => 220.00, 'preparation_time' => 40, 'description' => 'ورد هولندي طازج بتغليف كوري أنيق وبطاقة إهداء مجانية.'],
                ['slug' => 'white-lilies',      'name' => 'باقة زنبق أبيض',             'price' => 185.00, 'preparation_time' => 40, 'description' => 'زنبق أبيض مع أوراق يوكالبتوس في تغليف كلاسيكي.'],
                ['slug' => 'mixed-bouquet',     'name' => 'باقة مشكلة ألوان الربيع',    'price' => 160.00, 'preparation_time' => 35, 'description' => 'جربيرا وورد وليليوم بألوان مبهجة لكل المناسبات.'],
                ['slug' => 'orchid-pot',        'name' => 'أوركيد أبيض في أصيص',        'price' => 240.00, 'preparation_time' => 20, 'description' => 'نبتة أوركيد فالينوبسس بساقين في أصيص سيراميك.'],
                ['slug' => 'flower-box',        'name' => 'بوكس ورد مع شوكولاتة',       'price' => 320.00, 'preparation_time' => 45, 'description' => 'صندوق دائري بورد جوري وباتشي 250 غ.'],
                ['slug' => 'baby-bouquet',      'name' => 'باقة مولود جديد',            'price' => 199.00, 'preparation_time' => 40, 'description' => 'ورد بألوان هادئة مع بالون ودبدوب صغير.'],
                ['slug' => 'graduation-set',    'name' => 'ستاند تخرج',                 'price' => 450.00, 'preparation_time' => 90, 'description' => 'ستاند ورد طبيعي بارتفاع متر مع بالونات حسب اللون المطلوب.'],
                ['slug' => 'tulips-15',         'name' => 'باقة توليب - 15 عودًا',      'price' => 210.00, 'preparation_time' => 35, 'description' => 'توليب هولندي طازج بتغليف ورقي بسيط.'],
            ],
            'Perfumes' => [
                ['slug' => 'oud-cambodian',     'name' => 'دهن عود كمبودي - 12 مل',     'price' => 850.00, 'description' => 'عود كمبودي معتّق خمس سنوات، رائحة دافئة تدوم طويلًا.'],
                ['slug' => 'bakhoor-royal',     'name' => 'بخور ملكي - 100 غ',          'price' => 160.00, 'description' => 'بخور معطّر بالعود والعنبر في علبة فاخرة.'],
                ['slug' => 'musk-tahara',       'name' => 'مسك الطهارة الأبيض',          'price' => 45.00,  'description' => 'مسك أبيض نقي 12 مل بعبوة رول.'],
                ['slug' => 'amber-edp-100',     'name' => 'عطر عنبر شرقي - 100 مل',     'price' => 320.00, 'description' => 'عطر أو دو بارفان بنفحات العنبر والفانيليا والزعفران.'],
                ['slug' => 'perfume-gift-set',  'name' => 'طقم عطور هدايا - 3 قطع',     'price' => 480.00, 'description' => 'عطر 100 مل + دهن عود + بخور في صندوق فاخر.'],
                ['slug' => 'rose-taifi',        'name' => 'عطر الورد الطائفي - 50 مل',   'price' => 275.00, 'description' => 'مستخلص الورد الطائفي الأصلي بتركيز عالٍ.'],
                ['slug' => 'oud-spray',         'name' => 'بخاخ عود وعنبر - 75 مل',      'price' => 190.00, 'description' => 'عطر يومي بمزيج العود والعنبر وخشب الصندل.'],
            ],
            'Electronics' => [
                ['slug' => 'airpods-pro',       'name' => 'سماعات لاسلكية بعزل ضوضاء',    'price' => 899.00, 'description' => 'إلغاء ضوضاء نشط، شحن لاسلكي، مقاومة للماء والعرق.'],
                ['slug' => 'smart-watch',       'name' => 'ساعة ذكية 45 مم',              'price' => 1299.00, 'description' => 'تتبع اللياقة ونبض القلب والأكسجين مع بطارية ليومين.'],
                ['slug' => 'power-bank-20k',    'name' => 'بطارية متنقلة 20000 مللي أمبير', 'price' => 149.00, 'description' => 'شحن سريع 22.5 واط مع منفذين USB-C.'],
                ['slug' => 'bt-speaker',        'name' => 'سماعة بلوتوث محمولة',          'price' => 249.00, 'description' => 'صوت 360 درجة، مقاومة للماء، 12 ساعة تشغيل.'],
                ['slug' => 'gaming-headset',    'name' => 'سماعة ألعاب بميكروفون',        'price' => 329.00, 'description' => 'صوت محيطي 7.1 مع إضاءة RGB وميكروفون قابل للفصل.'],
                ['slug' => 'usbc-charger-65',   'name' => 'شاحن GaN 65 واط',              'price' => 119.00, 'description' => 'ثلاثة منافذ لشحن اللابتوب والجوال معًا.'],
                ['slug' => 'instant-camera',    'name' => 'كاميرا فورية مع 20 ورقة',      'price' => 449.00, 'description' => 'كاميرا طباعة فورية بتصميم عصري، هدية مثالية.'],
            ],
            'Clothing' => [
                ['slug' => 'thobe-classic',     'name' => 'ثوب رجالي كلاسيكي',            'price' => 180.00, 'description' => 'قماش ياباني قطني مريح، قصّة سعودية، متوفر بعدة مقاسات.'],
                ['slug' => 'shemagh-red',       'name' => 'شماغ أحمر فاخر',              'price' => 120.00, 'description' => 'شماغ قطني بنقشة كلاسيكية وحواف مطرزة.'],
                ['slug' => 'abaya-embroidered', 'name' => 'عباية مطرزة',                  'price' => 350.00, 'description' => 'قماش كريب أسود مع تطريز يدوي على الأكمام.'],
                ['slug' => 'kids-jalabiya',     'name' => 'جلابية أطفال للعيد',           'price' => 95.00,  'description' => 'قماش قطني ناعم بألوان مبهجة، مقاسات 2-10 سنوات.'],
                ['slug' => 'hoodie-oversize',   'name' => 'هودي أوفرسايز',                'price' => 140.00, 'description' => 'قطن ثقيل 400 غ، تصميم مريح بجيب أمامي.'],
                ['slug' => 'silk-scarf',        'name' => 'وشاح حرير مطبوع',              'price' => 165.00, 'description' => 'حرير طبيعي 100% بطباعة زهرية، 90×90 سم.'],
                ['slug' => 'leather-wallet',    'name' => 'محفظة جلد طبيعي',              'price' => 210.00, 'description' => 'جلد إيطالي مع نقش الاسم مجانًا، تصلح كهدية.'],
            ],
            'Groceries' => [
                ['slug' => 'honey-sidr-1kg',    'name' => 'عسل سدر جبلي - 1 كغ',           'price' => 380.00, 'expiry_days' => 365, 'description' => 'عسل سدر أصلي من جبال الجنوب، مفحوص مخبريًا.'],
                ['slug' => 'saudi-coffee-500',  'name' => 'قهوة سعودية مطحونة - 500 غ',    'price' => 48.00,  'expiry_days' => 180, 'description' => 'بن خولاني فاتح مع الهيل والزعفران.'],
                ['slug' => 'dates-sukkari-3kg', 'name' => 'تمر سكري القصيم - 3 كغ',       'price' => 110.00, 'expiry_days' => 240, 'description' => 'تمر سكري رطب درجة أولى في كرتون مبطن.'],
                ['slug' => 'olive-oil-1l',      'name' => 'زيت زيتون بكر ممتاز - 1 لتر',   'price' => 75.00,  'expiry_days' => 540, 'description' => 'عصرة أولى على البارد، حموضة أقل من 0.5%.'],
                ['slug' => 'ghee-1kg',          'name' => 'سمن بلدي - 1 كغ',              'price' => 95.00,  'expiry_days' => 365, 'description' => 'سمن بقري بلدي طبيعي بدون إضافات.'],
                ['slug' => 'nuts-mix-1kg',      'name' => 'مكسرات مشكلة محمصة - 1 كغ',   'price' => 135.00, 'expiry_days' => 120, 'description' => 'كاجو ولوز وفستق وبندق محمّص بدون ملح.'],
                ['slug' => 'saffron-5g',        'name' => 'زعفران إيراني فاخر - 5 غ',      'price' => 85.00,  'expiry_days' => 720, 'description' => 'زعفران سوبر نقيل بخيوط حمراء كاملة.'],
            ],
            'Pharmacy' => [
                ['slug' => 'vitamin-d-5000',    'name' => 'فيتامين د 5000 وحدة - 90 كبسولة', 'price' => 65.00,  'expiry_days' => 540, 'description' => 'مكمل غذائي يومي لدعم العظام والمناعة.'],
                ['slug' => 'omega-3',           'name' => 'أوميغا 3 - 120 كبسولة',          'price' => 89.00,  'expiry_days' => 540, 'description' => 'زيت سمك نقي بتركيز عالٍ من EPA وDHA.'],
                ['slug' => 'baby-care-set',     'name' => 'طقم عناية بالمولود',              'price' => 145.00, 'expiry_days' => 720, 'description' => 'شامبو ولوشن وزيت وكريم طفح في حقيبة هدية.'],
                ['slug' => 'sunscreen-spf50',   'name' => 'واقي شمس SPF 50 - 50 مل',        'price' => 78.00,  'expiry_days' => 720, 'description' => 'حماية واسعة الطيف، خفيف وبدون أثر أبيض.'],
                ['slug' => 'first-aid-kit',     'name' => 'حقيبة إسعافات أولية',             'price' => 55.00,  'description' => '60 قطعة أساسية للمنزل والسيارة.'],
                ['slug' => 'thermometer',       'name' => 'ميزان حرارة رقمي بدون لمس',       'price' => 120.00, 'description' => 'قراءة خلال ثانية واحدة مع ذاكرة 32 قياسًا.'],
                ['slug' => 'collagen-powder',   'name' => 'بودرة كولاجين - 300 غ',          'price' => 160.00, 'expiry_days' => 540, 'description' => 'كولاجين بحري بنكهة الفانيليا، يذوب بسهولة.'],
            ],
            'Toys' => [
                ['slug' => 'lego-city',         'name' => 'مجموعة مكعبات بناء - محطة إطفاء', 'price' => 249.00, 'description' => '520 قطعة مع شخصيتين وسيارة إطفاء، للأعمار 6+.'],
                ['slug' => 'teddy-bear-60',     'name' => 'دبدوب قطيفة 60 سم',              'price' => 95.00,  'description' => 'ناعم وآمن للأطفال، متوفر بالبني والأبيض.'],
                ['slug' => 'rc-car',            'name' => 'سيارة تحكم عن بعد 4×4',           'price' => 189.00, 'description' => 'سرعة 20 كم/س، بطارية قابلة للشحن، للأعمار 8+.'],
                ['slug' => 'puzzle-1000',       'name' => 'بازل 1000 قطعة - خريطة العالم',   'price' => 65.00,  'description' => 'قطع كرتون سميكة بألوان زاهية، 70×50 سم.'],
                ['slug' => 'doll-house',        'name' => 'بيت دمى خشبي 3 طوابق',            'price' => 320.00, 'description' => 'مع 15 قطعة أثاث مصغّرة، خشب طبيعي.'],
                ['slug' => 'board-game-family', 'name' => 'لعبة لوحية عائلية',               'price' => 110.00, 'description' => 'من 2 إلى 6 لاعبين، نسخة عربية، للأعمار 8+.'],
                ['slug' => 'kids-scooter',      'name' => 'سكوتر أطفال 3 عجلات',             'price' => 175.00, 'description' => 'عجلات مضيئة وارتفاع قابل للتعديل، حتى 50 كغ.'],
            ],
            'Books & Stationery' => [
                ['slug' => 'quran-gift',        'name' => 'مصحف هدية بغلاف جلدي',          'price' => 120.00, 'description' => 'طباعة ملونة بخط واضح مع علبة مخملية.'],
                ['slug' => 'planner-2027',      'name' => 'مفكرة سنوية 2027',               'price' => 85.00,  'description' => 'مخطط يومي بغلاف صلب وشريط علامة، 384 صفحة.'],
                ['slug' => 'fountain-pen',      'name' => 'قلم حبر فاخر في علبة هدايا',     'price' => 260.00, 'description' => 'جسم معدني بطلاء أسود لامع، مع نقش الاسم مجانًا.'],
                ['slug' => 'novel-arabic',      'name' => 'رواية عربية - أكثر الكتب مبيعًا', 'price' => 55.00,  'description' => 'الطبعة الأحدث بغلاف ورقي.'],
                ['slug' => 'kids-stories-set',  'name' => 'مجموعة قصص أطفال - 10 كتب',      'price' => 140.00, 'description' => 'قصص مصورة بالحركات لتعليم القراءة، للأعمار 4-8.'],
                ['slug' => 'art-set-120',       'name' => 'طقم رسم وتلوين 120 قطعة',        'price' => 130.00, 'description' => 'ألوان خشبية ومائية وشمعية في حقيبة خشبية.'],
                ['slug' => 'desk-organizer',    'name' => 'منظم مكتب خشبي',                  'price' => 90.00,  'description' => 'خشب بامبو بـ 6 أقسام للأقلام والملاحظات.'],
            ],
        ];
    }
}
