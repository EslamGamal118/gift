<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Filament\Resources\AddonResource;
use App\Models\Addon;
use App\Models\Product;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Add-ons offered with the product. Add-ons are linked to categories, not to
 * products: a customer ordering this product can add the store's active add-ons
 * linked to the product's category (Product::availableAddons). Inactive ones
 * are listed too, so the admin sees what is switched off.
 */
class AddonsRelationManager extends RelationManager
{
    // Nominal only — the table below sets its own query (store + category)
    protected static string $relationship = 'orderItems';

    protected static ?string $icon = 'heroicon-o-sparkles';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.products.addons_title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) self::query($ownerRecord)->active()->count();
    }

    protected static function getModelLabel(): ?string
    {
        return __('admin.addons.model');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('admin.addons.title');
    }

    /**
     * The store's add-ons linked to the product's category (active or not).
     */
    public static function query(Product $product): Builder
    {
        return Addon::query()->forStore($product->store_id)->forCategory($product->category_id);
    }

    public function table(Table $table): Table
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        return $table
            ->query(fn () => self::query($product)->with('categories'))
            ->recordTitleAttribute('name')
            ->heading(__('admin.products.addons_title'))
            ->description(__('admin.products.addons_hint', ['category' => $product->category?->name]))
            ->defaultSort('name')
            ->recordUrl(fn (Addon $record) => AddonResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading(__('admin.products.no_addons'))
            ->emptyStateDescription(__('admin.products.no_addons_hint'))
            ->emptyStateIcon('heroicon-o-sparkles')
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label(__('admin.addons.image'))
                    ->disk('public')
                    ->square()
                    ->defaultImageUrl(fn (Addon $record) => AddonResource::placeholderImage($record)),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('admin.addons.name'))
                    ->searchable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('categories.name')
                    ->label(__('admin.addons.categories'))
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('price')
                    ->label(__('admin.addons.price'))
                    ->money('SAR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('stock_quantity')
                    ->label(__('admin.addons.stock'))
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'danger'),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label(__('admin.addons.is_active')),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('admin.addons.is_active')),
            ]);
    }
}
