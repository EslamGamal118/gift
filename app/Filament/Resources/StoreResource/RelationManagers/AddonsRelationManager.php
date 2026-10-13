<?php

namespace App\Filament\Resources\StoreResource\RelationManagers;

use App\Models\Addon;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Gift extras the store offers with its products (wrapping, cards, balloons...).
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with('categories'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label(__('admin.products.image'))
                    ->disk('public')
                    ->square()
                    ->defaultImageUrl(fn (Addon $record) => 'https://ui-avatars.com/api/?background=ede9f3&color=461959&name='.urlencode(mb_substr($record->name, 0, 2))),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('admin.addons.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('categories.name')
                    ->label(__('admin.addons.categories'))
                    ->badge()
                    ->color('gray')
                    ->placeholder(__('admin.addons.no_categories')),
                Tables\Columns\TextColumn::make('price')
                    ->label(__('admin.addons.price'))
                    ->money('SAR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('stock_quantity')
                    ->label(__('admin.addons.stock'))
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'danger')
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label(__('admin.addons.is_active')),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('admin.addons.is_active')),
            ]);
    }
}
