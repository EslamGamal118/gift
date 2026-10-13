<?php

namespace App\Filament\Resources\CategoryResource\RelationManagers;

use App\Filament\Resources\AddonResource;
use App\Filament\Support\Lookups;
use App\Models\Addon;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Add-ons linked to the category. Each one is offered with its own store's
 * products of this category (Product::availableAddons). A row opens the add-on.
 */
class AddonsRelationManager extends RelationManager
{
    protected static string $relationship = 'addons';

    protected static ?string $icon = 'heroicon-o-sparkles';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.addons.title');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->addons()->count();
    }

    protected static function getModelLabel(): ?string
    {
        return __('admin.addons.model');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('store.storeProfile'))
            ->defaultSort('addons.created_at', 'desc')
            ->recordUrl(fn (Addon $record) => AddonResource::getUrl('view', ['record' => $record]))
            ->description(__('admin.addons.category_hint'))
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
                Tables\Columns\TextColumn::make('store.storeProfile.store_name')
                    ->label(__('admin.addons.store'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('price')
                    ->label(__('admin.addons.price'))
                    ->money('SAR')
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label(__('admin.addons.is_active')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('store_id')
                    ->label(__('admin.addons.store'))
                    ->options(fn () => Lookups::stores())
                    ->searchable(),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('admin.addons.is_active')),
            ]);
    }
}
