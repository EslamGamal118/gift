<?php

namespace App\Filament\Resources\StoreResource\RelationManagers;

use App\Models\Review;
use App\Models\StoreReview;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Customer reviews of the store. Hiding one (is_visible) removes it from the
 * store page and recalculates the store's rating (StoreReview model events).
 * The products rating comes from the order's review (reviews table).
 */
class ReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'reviews';

    protected static ?string $icon = 'heroicon-o-star';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.store_reviews.title');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->reviews()->count();
    }

    protected static function getModelLabel(): ?string
    {
        return __('admin.reviews.model');
    }

    public static function stars(?int $rating): string
    {
        $rating = max(0, min(Review::MAX_RATING, (int) $rating));

        return str_repeat('★', $rating).str_repeat('☆', Review::MAX_RATING - $rating);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['user', 'order'])
                ->addSelect(['products_rating' => Review::query()
                    ->select('products_rating')
                    ->where('reviewable_type', Review::TYPE_ORDER)
                    ->whereColumn('reviewable_id', 'store_reviews.order_id')
                    ->limit(1)]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('admin.store_reviews.customer'))
                    ->description(fn (StoreReview $record) => $record->order?->order_number)
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('rating')
                    ->label(__('admin.store_reviews.rating'))
                    ->formatStateUsing(fn (?int $state) => self::stars($state))
                    ->color('warning')
                    ->description(fn (StoreReview $record) => $record->products_rating
                        ? __('admin.reviews.products_rating').': '.self::stars($record->products_rating)
                        : null)
                    ->sortable(),
                Tables\Columns\TextColumn::make('comment')
                    ->label(__('admin.store_reviews.comment'))
                    ->limit(80)
                    ->tooltip(fn (StoreReview $record) => $record->comment)
                    ->wrap()
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\ToggleColumn::make('is_visible')
                    ->label(__('admin.store_reviews.is_visible')),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('admin.common.created_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('rating')
                    ->label(__('admin.store_reviews.rating'))
                    ->options(collect(range(Review::MAX_RATING, Review::MIN_RATING))->mapWithKeys(fn (int $n) => [$n => self::stars($n)])),
                Tables\Filters\TernaryFilter::make('is_visible')
                    ->label(__('admin.store_reviews.is_visible')),
            ]);
    }
}
