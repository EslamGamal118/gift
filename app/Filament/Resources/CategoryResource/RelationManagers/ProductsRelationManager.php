<?php

namespace App\Filament\Resources\CategoryResource\RelationManagers;

use App\Filament\Resources\ProductResource;
use App\Filament\Support\Lookups;
use App\Filament\Support\ProductDisplay;
use App\Models\Product;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Products of the category, across every store. A row opens the product.
 */
class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $icon = 'heroicon-o-gift';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.products.title');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->products()->count();
    }

    protected static function getModelLabel(): ?string
    {
        return __('admin.products.model');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('storeProfile'))
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Product $record) => ProductResource::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label(__('admin.products.image'))
                    ->disk('public')
                    ->square()
                    ->defaultImageUrl(fn (Product $record) => ProductResource::placeholderImage($record)),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('admin.products.name'))
                    ->searchable()
                    ->weight('medium')
                    ->wrap(),
                Tables\Columns\TextColumn::make('storeProfile.store_name')
                    ->label(__('admin.products.store'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('price')
                    ->label(__('admin.products.price'))
                    ->money('SAR')
                    ->sortable(),
                ProductDisplay::stockColumn(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('store_id')
                    ->label(__('admin.products.store'))
                    ->options(fn () => Lookups::stores())
                    ->searchable(),
                ProductDisplay::stockFilter(),
            ]);
    }
}
