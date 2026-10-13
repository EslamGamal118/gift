<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\AddonResource;
use App\Filament\Resources\AddonResource\Pages\CreateAddon;
use App\Filament\Resources\AddonResource\Pages\ListAddons;
use App\Filament\Resources\AddonResource\Pages\ViewAddon;
use App\Filament\Resources\AddonResource\RelationManagers\ProductsRelationManager as AddonProductsRelationManager;
use App\Filament\Resources\CategoryResource;
use App\Filament\Resources\CategoryResource\Pages\CreateCategory;
use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Filament\Resources\CategoryResource\Pages\ListCategories;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ProductResource\Pages\ViewProduct;
use App\Filament\Resources\ProductResource\RelationManagers\AddonsRelationManager as ProductAddonsRelationManager;
use App\Models\Addon;
use App\Models\Category;
use App\Models\Product;
use App\Models\StoreProfile;
use App\Models\User;
use Filament\Tables\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogResourcesTest extends TestCase
{
    use RefreshDatabase;

    private StoreProfile $store;

    private Category $flowers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->flowers = Category::factory()->create(['name' => ['ar' => 'ورود', 'en' => 'Flowers']]);
        $this->store = StoreProfile::factory()->create(['store_name' => 'Rose House']);
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->create($attributes + ['store_id' => $this->store->user_id, 'category_id' => $this->flowers->id]);
    }

    private function addon(array $attributes = [], ?array $categories = null): Addon
    {
        $addon = Addon::factory()->create($attributes + ['store_id' => $this->store->user_id]);
        $addon->categories()->sync($categories ?? [$this->flowers->id]);

        return $addon;
    }

    public function test_the_three_resources_share_one_arabic_navigation_group(): void
    {
        app()->setLocale('ar');

        $this->assertSame('إدارة الكتالوج', CategoryResource::getNavigationGroup());
        $this->assertSame(CategoryResource::getNavigationGroup(), ProductResource::getNavigationGroup());
        $this->assertSame(CategoryResource::getNavigationGroup(), AddonResource::getNavigationGroup());
        $this->assertSame(['إدارة الأقسام', 'إدارة المنتجات', 'إدارة الإضافات'], [
            CategoryResource::getNavigationLabel(), ProductResource::getNavigationLabel(), AddonResource::getNavigationLabel(),
        ]);

        $html = $this->get('/admin')->assertOk()->getContent();
        $this->assertStringContainsString('إدارة الكتالوج', $html);
    }

    public function test_categories_keep_both_translations_and_only_empty_ones_can_be_deleted(): void
    {
        Livewire::test(CreateCategory::class)
            ->fillForm(['name.ar' => 'شوكولاتة', 'name.en' => 'Chocolate', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $chocolate = Category::all()->first(fn (Category $c) => $c->getTranslation('name', 'en') === 'Chocolate');
        $this->assertSame(['ar' => 'شوكولاتة', 'en' => 'Chocolate'], $chocolate->getTranslations('name'));

        Livewire::test(EditCategory::class, ['record' => $chocolate->getRouteKey()])
            ->assertFormSet(['name.ar' => 'شوكولاتة', 'name.en' => 'Chocolate'])
            ->fillForm(['name.en' => 'Chocolates'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(['ar' => 'شوكولاتة', 'en' => 'Chocolates'], $chocolate->fresh()->getTranslations('name'));

        // Search covers both languages; counts come from the relationships
        $this->product();
        Livewire::test(ListCategories::class)
            ->searchTable('Flowers')->assertCanSeeTableRecords([$this->flowers])->assertCanNotSeeTableRecords([$chocolate])
            ->searchTable('شوكو')->assertCanSeeTableRecords([$chocolate])->assertCanNotSeeTableRecords([$this->flowers])
            ->searchTable('')
            // Deleting a category deletes its products (FK cascade): only empty ones offer delete
            ->assertTableActionHidden(DeleteAction::class, $this->flowers)
            ->assertTableActionVisible(DeleteAction::class, $chocolate);
    }

    public function test_product_addons_tab_shows_the_stores_addons_of_the_product_category(): void
    {
        $product = $this->product();
        $card = $this->addon(['name' => 'Greeting card']);
        $wrap = $this->addon(['name' => 'Gift wrap', 'is_active' => false]);
        $otherCategory = $this->addon(['name' => 'Candle'], [Category::factory()->create()->id]);
        $otherStore = Addon::factory()->create(['name' => 'Balloons']);   // same category, another store
        $otherStore->categories()->sync([$this->flowers->id]);

        Livewire::test(ProductAddonsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => ViewProduct::class])
            ->assertCanSeeTableRecords([$card, $wrap])
            ->assertCanNotSeeTableRecords([$otherCategory, $otherStore])
            ->call('updateTableColumnState', 'is_active', (string) $card->id, false);

        // Same rule as the app: only active ones reach the customer
        $this->assertSame([], $product->availableAddons()->pluck('id')->all());
        $this->assertSame('0', ProductAddonsRelationManager::getBadge($product, ViewProduct::class));

        $this->get(ProductResource::getUrl('view', ['record' => $product]))->assertOk()->assertSee('Rose House');
    }

    public function test_addon_products_tab_is_the_reverse_relation(): void
    {
        $roses = $this->product(['name' => 'Red roses']);
        $otherStoreProduct = Product::factory()->create(['category_id' => $this->flowers->id]);
        $addon = $this->addon(['name' => 'Greeting card']);

        Livewire::test(AddonProductsRelationManager::class, ['ownerRecord' => $addon, 'pageClass' => ViewAddon::class])
            ->assertCanSeeTableRecords([$roses])
            ->assertCanNotSeeTableRecords([$otherStoreProduct]);

        $unlinked = Addon::factory()->create(['store_id' => $this->store->user_id]);
        Livewire::test(ListAddons::class)
            ->filterTable('unlinked', true)
            ->assertCanSeeTableRecords([$unlinked])
            ->assertCanNotSeeTableRecords([$addon]);
    }

    public function test_addon_form_requires_a_store_and_categories(): void
    {
        Livewire::test(CreateAddon::class)
            ->fillForm(['name' => 'Balloons', 'price' => 15, 'stock_quantity' => 5])
            ->call('create')
            ->assertHasFormErrors(['store_id' => 'required', 'categories' => 'required']);

        Livewire::test(CreateAddon::class)
            ->fillForm(['name' => 'Balloons', 'price' => 15, 'stock_quantity' => 5, 'store_id' => $this->store->user_id, 'categories' => [$this->flowers->id], 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $addon = Addon::where('name', 'Balloons')->sole();
        $this->assertSame($this->store->user_id, $addon->store_id);
        $this->assertSame([$this->flowers->id], $addon->categories()->pluck('categories.id')->all());
    }

    public function test_product_form_places_the_product_and_handles_gift_validity(): void
    {
        $gifts = Category::factory()->create(['is_special' => true]);

        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'Gift card', 'price' => 100, 'stock_quantity' => 10, 'preparation_time' => 5, 'store_id' => $this->store->user_id, 'category_id' => $gifts->id, 'gift_validity_days' => 30])
            ->assertFormFieldIsVisible('gift_validity_days')
            ->call('create')
            ->assertHasNoFormErrors();

        $card = Product::where('name', 'Gift card')->sole();
        $this->assertSame(30, (int) $card->gift_validity_days);

        // Moved out of the online gifts category: the gift validity no longer applies
        Livewire::test(EditProduct::class, ['record' => $card->getRouteKey()])
            ->fillForm(['category_id' => $this->flowers->id])
            ->assertFormFieldIsHidden('gift_validity_days')
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertNull($card->fresh()->gift_validity_days);

        Livewire::test(ListProducts::class)
            ->filterTable('store_id', $this->store->user_id)
            ->assertCanSeeTableRecords([$card])
            ->assertTableColumnStateSet('storeProfile.store_name', 'Rose House', $card);
    }

    public function test_list_pages_are_arabic_and_aligned(): void
    {
        $this->addon();
        $this->product();

        foreach (['categories', 'products', 'addons'] as $slug) {
            $html = $this->get("/admin/{$slug}")->assertOk()->getContent();
            $this->assertStringContainsString('dir="rtl"', $html);
            $this->assertStringNotContainsString('dir="ltr"', $html);
            $this->assertStringContainsString(__('admin.common.actions'), $html);
            $this->assertMatchesRegularExpression('/fi-ta-actions-cell.*?fi-ta-actions [^"]*justify-start/s', $html);
        }
    }
}
