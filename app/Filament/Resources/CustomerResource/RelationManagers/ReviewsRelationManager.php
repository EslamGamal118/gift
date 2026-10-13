<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Models\CustomOrder;
use App\Models\Order;
use App\Models\Review;
use App\Models\StoreReview;
use App\Services\ReviewService;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Reviews the customer wrote for finished orders. Store order reviews are
 * moderated on their `store_reviews` copy (shown on the store page), personal
 * shopper reviews on `reviews.is_visible` (see ReviewService::setVisibility).
 */
class ReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'reviews';

    protected static ?string $icon = 'heroicon-o-star';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.reviews.title');
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

    public static function target(Review $review): ?string
    {
        return match (true) {
            $review->reviewable instanceof Order => $review->reviewable->storeProfile?->store_name,
            $review->reviewable instanceof CustomOrder => $review->reviewable->shopper?->name,
            default => null,
        };
    }

    /**
     * Is the review shown publicly (see ReviewService::isVisible)? Listed store
     * order rows carry it from a subselect; a row loaded on its own (e.g. when an
     * action runs) asks the service.
     */
    public static function visibility(Review $review): ?bool
    {
        if ($review->reviewable_type === Review::TYPE_ORDER && array_key_exists('store_review_visible', $review->getAttributes())) {
            return $review->store_review_visible === null ? null : (bool) $review->store_review_visible;
        }

        return app(ReviewService::class)->isVisible($review);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['reviewable' => fn (MorphTo $morph) => $morph->morphWith([
                    Order::class => ['storeProfile'],
                    CustomOrder::class => ['shopper'],
                ])])
                ->addSelect(['store_review_visible' => StoreReview::query()
                    ->select('is_visible')
                    ->whereColumn('store_reviews.order_id', 'reviews.reviewable_id')
                    ->where('reviews.reviewable_type', Review::TYPE_ORDER)
                    ->limit(1)]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('reviewable_type')
                    ->label(__('admin.reviews.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.reviews.types.$state"))
                    ->color(fn (string $state) => $state === Review::TYPE_ORDER ? 'primary' : 'info'),
                Tables\Columns\TextColumn::make('target')
                    ->label(__('admin.reviews.target'))
                    ->state(fn (Review $record) => self::target($record))
                    ->description(fn (Review $record) => $record->reviewable?->order_number)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('store_rating')
                    ->label(__('admin.reviews.store_rating'))
                    ->formatStateUsing(fn (?int $state) => self::stars($state))
                    ->color('warning')
                    ->description(fn (Review $record) => __('admin.reviews.products_rating').': '.self::stars($record->products_rating))
                    ->sortable(),
                Tables\Columns\TextColumn::make('store_comment')
                    ->label(__('admin.reviews.store_comment'))
                    ->limit(60)
                    ->tooltip(fn (Review $record) => $record->store_comment)
                    ->description(fn (Review $record) => $record->products_comment ? str($record->products_comment)->limit(60) : null)
                    ->wrap()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('store_review_visible')
                    ->label(__('admin.reviews.visibility'))
                    ->badge()
                    ->state(fn (Review $record) => match (self::visibility($record)) {
                        null => 'none',
                        true => 'visible',
                        false => 'hidden',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'visible' => __('admin.reviews.visible'),
                        'hidden' => __('admin.reviews.hidden'),
                        default => __('admin.reviews.not_moderated'),
                    })
                    ->color(fn (string $state) => match ($state) {
                        'visible' => 'success',
                        'hidden' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('admin.common.created_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('reviewable_type')
                    ->label(__('admin.reviews.type'))
                    ->options(__('admin.reviews.types')),
                Tables\Filters\SelectFilter::make('store_rating')
                    ->label(__('admin.reviews.min_rating'))
                    ->options(collect(range(Review::MAX_RATING, Review::MIN_RATING))->mapWithKeys(fn (int $n) => [$n => self::stars($n)]))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $min) => $q->where('store_rating', '>=', $min))),
            ])
            ->actions([
                Tables\Actions\Action::make('toggleVisibility')
                    ->label(fn (Review $record) => self::visibility($record) ? __('admin.reviews.hide') : __('admin.reviews.show'))
                    ->icon(fn (Review $record) => self::visibility($record) ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                    ->color(fn (Review $record) => self::visibility($record) ? 'danger' : 'success')
                    ->visible(fn (Review $record) => self::visibility($record) !== null)
                    ->requiresConfirmation()
                    ->action(function (Review $record): void {
                        $visible = ! app(ReviewService::class)->isVisible($record);
                        app(ReviewService::class)->setVisibility($record, $visible);   // also refreshes the store / shopper rating

                        Notification::make()
                            ->title($visible ? __('admin.reviews.shown_done') : __('admin.reviews.hidden_done'))
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
