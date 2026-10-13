<?php

namespace App\Filament\Resources\ShopperResource\RelationManagers;

use App\Filament\Resources\ShopperResource;
use App\Models\Review;
use App\Services\ReviewService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Customer reviews of the shopper after completed custom orders. Hiding one
 * (reviews.is_visible) removes it from the app's shopper reviews and from the
 * shopper's rating (ReviewService::setVisibility).
 */
class ReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'reviews';

    protected static ?string $icon = 'heroicon-o-star';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.shoppers.tabs.reviews');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->reviews()->count();
    }

    protected static function getModelLabel(): ?string
    {
        return __('admin.reviews.model');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'reviewable']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('admin.store_reviews.customer'))
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('reviewable.order_number')
                    ->label(__('admin.orders.number'))
                    ->copyable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('store_rating')
                    ->label(__('admin.store_reviews.rating'))
                    ->formatStateUsing(fn (?int $state) => ShopperResource::stars($state))
                    ->color('warning')
                    ->sortable(),
                Tables\Columns\TextColumn::make('store_comment')
                    ->label(__('admin.store_reviews.comment'))
                    ->limit(80)
                    ->tooltip(fn (Review $record) => $record->store_comment)
                    ->wrap()
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('admin.common.created_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('is_visible')
                    ->label(__('admin.shoppers.reviews.is_visible'))
                    // Through ReviewService so the shopper's rating follows
                    ->updateStateUsing(function (Review $record, bool $state): bool {
                        app(ReviewService::class)->setVisibility($record, $state);

                        return $state;
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('store_rating')
                    ->label(__('admin.store_reviews.rating'))
                    ->options(collect(range(Review::MAX_RATING, Review::MIN_RATING))->mapWithKeys(fn (int $n) => [$n => ShopperResource::stars($n)])),
                Tables\Filters\TernaryFilter::make('is_visible')
                    ->label(__('admin.shoppers.reviews.is_visible')),
            ]);
    }
}
