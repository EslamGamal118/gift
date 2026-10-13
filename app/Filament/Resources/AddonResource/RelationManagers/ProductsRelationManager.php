<?php

namespace App\Filament\Resources\AddonResource\RelationManagers;

use App\Filament\Resources\ProductResource;
use App\Filament\Support\ProductDisplay;
use App\Models\Addon;
use App\Models\Product;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Products the add-on is offered with: its store's products in the add-on's
 * categories (the reverse of Product::availableAddons).
 */
class ProductsRelationManager extends RelationManager
{
    // Nominal only — the table below sets its own query (store + categories)
    protected static string $relationship = 'categories';

    protected static ?string $icon = 'heroicon-o-gift';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.addons.products_title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) self::query($ownerRecord)->count();
    }

    protected static function getModelLabel(): ?string
    {
        return __('admin.products.model');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('admin.products.title');
    }

    public static function query(Addon $addon): Builder
    {
        return Product::query()
            ->where('products.store_id', $addon->store_id)
            ->whereIn('products.category_id', $addon->categories()->select('categories.id'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => self::query($this->getOwnerRecord())->with('category'))
            ->recordTitleAttribute('name')
            ->heading(__('admin.addons.products_title'))
            ->description(__('admin.addons.products_hint'))
            ->defaultSort('name')
            ->recordUrl(fn (Product $record) => ProductResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading(__('admin.addons.no_products'))
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label(__('admin.products.image'))
                    ->disk('public')
                    ->square()
                    ->defaultImageUrl(fn (Product $record) => ProductResource::placeholderImage($record)),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('admin.products.name'))
                    ->searchable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('category.name')
                    ->label(__('admin.products.category'))
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('price')
                    ->label(__('admin.products.price'))
                    ->money('SAR'),
                ProductDisplay::stockColumn(),
            ]);
    }
}
