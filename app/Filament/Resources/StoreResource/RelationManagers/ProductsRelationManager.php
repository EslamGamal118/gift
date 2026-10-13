<?php

namespace App\Filament\Resources\StoreResource\RelationManagers;

use App\Filament\Resources\StoreResource;
use App\Filament\Support\ProductDisplay;
use App\Models\Product;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The store's products (owned by the store's user account).
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label(__('admin.products.image'))
                    ->disk('public')
                    ->square()
                    ->defaultImageUrl(fn (Product $record) => 'https://ui-avatars.com/api/?background=ede9f3&color=461959&name='.urlencode(mb_substr($record->name, 0, 2))),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('admin.products.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Product $record) => $record->category?->name)
                    ->wrap(),
                Tables\Columns\TextColumn::make('price')
                    ->label(__('admin.products.price'))
                    ->money('SAR')
                    ->sortable(),
                ProductDisplay::stockColumn(),
                Tables\Columns\TextColumn::make('expiry_date')
                    ->label(__('admin.products.expiry_date'))
                    ->date('Y-m-d')
                    ->color(fn (Product $record) => $record->isExpired() ? 'danger' : null)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('rating_avg')
                    ->label(__('admin.products.rating'))
                    ->formatStateUsing(fn ($state, Product $record) => $record->rating_count ? number_format((float) $state, 1).' ★ ('.$record->rating_count.')' : '—')
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('is_featured')
                    ->label(__('admin.products.is_featured')),
            ])
            ->filters([
                ProductDisplay::stockFilter(),
                Tables\Filters\SelectFilter::make('category_id')
                    ->label(__('admin.stores.fields.category'))
                    ->options(fn () => StoreResource::categoryOptions()),
                Tables\Filters\TernaryFilter::make('is_featured')
                    ->label(__('admin.products.is_featured')),
            ]);
    }
}
