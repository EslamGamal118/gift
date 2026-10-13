<?php

namespace App\Filament\Support;

use App\Filament\Resources\CustomerResource\RelationManagers\ReviewsRelationManager;
use App\Models\Review;
use App\Services\ReviewService;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * The customer's review of one order (store or personal shopper) on the order
 * pages, with the admin's show / hide switch (ReviewService::setVisibility).
 */
class ReviewDisplay
{
    /**
     * Shown / hidden, or null when the review cannot be moderated (a store
     * order review without its store_reviews copy).
     */
    public static function visibility(?Review $review): ?bool
    {
        return $review ? ReviewsRelationManager::visibility($review) : null;
    }

    /**
     * @param  string  $ratingLabel  what `store_rating` rates here (the store or the shopper)
     */
    public static function section(string $ratingLabel): Infolists\Components\Section
    {
        return Infolists\Components\Section::make(__('admin.order_reviews.title'))
            ->key('review')
            ->icon('heroicon-o-star')
            ->headerActions([self::toggleAction()])
            ->columns(2)
            ->schema([
                Infolists\Components\TextEntry::make('no_review')
                    ->hiddenLabel()
                    ->state(__('admin.order_reviews.none'))
                    ->color('gray')
                    ->columnSpanFull()
                    ->visible(fn (Model $record) => $record->review === null),
                Infolists\Components\TextEntry::make('review.store_rating')
                    ->label($ratingLabel)
                    ->formatStateUsing(fn (?int $state) => ReviewsRelationManager::stars($state).'  ('.$state.'/'.Review::MAX_RATING.')')
                    ->color('warning'),
                Infolists\Components\TextEntry::make('review.products_rating')
                    ->label(__('admin.reviews.products_rating'))
                    ->formatStateUsing(fn (?int $state) => ReviewsRelationManager::stars($state).'  ('.$state.'/'.Review::MAX_RATING.')')
                    ->color('warning'),
                Infolists\Components\TextEntry::make('review.store_comment')
                    ->label(__('admin.reviews.store_comment'))
                    ->placeholder('—')
                    ->visible(fn (Model $record) => $record->review !== null),
                Infolists\Components\TextEntry::make('review.products_comment')
                    ->label(__('admin.reviews.products_comment'))
                    ->placeholder('—')
                    ->visible(fn (Model $record) => $record->review !== null),
                Infolists\Components\TextEntry::make('review_visibility')
                    ->label(__('admin.reviews.visibility'))
                    ->badge()
                    ->state(fn (Model $record) => match (self::visibility($record->review)) {
                        true => __('admin.reviews.visible'),
                        false => __('admin.reviews.hidden'),
                        null => __('admin.reviews.not_moderated'),
                    })
                    ->color(fn (Model $record) => match (self::visibility($record->review)) {
                        true => 'success',
                        false => 'danger',
                        null => 'gray',
                    })
                    ->visible(fn (Model $record) => $record->review !== null),
                Infolists\Components\TextEntry::make('review.created_at')
                    ->label(__('admin.order_reviews.reviewed_at'))
                    ->dateTime('Y-m-d H:i'),
            ]);
    }

    public static function toggleAction(): Infolists\Components\Actions\Action
    {
        return Infolists\Components\Actions\Action::make('toggleReviewVisibility')
            ->label(fn (Model $record) => self::visibility($record->review) ? __('admin.reviews.hide') : __('admin.reviews.show'))
            ->icon(fn (Model $record) => self::visibility($record->review) ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
            ->color(fn (Model $record) => self::visibility($record->review) ? 'danger' : 'success')
            ->visible(fn (Model $record) => self::visibility($record->review) !== null)
            ->requiresConfirmation()
            ->action(function (Model $record): void {
                $review = $record->review;
                $visible = ! app(ReviewService::class)->isVisible($review);
                app(ReviewService::class)->setVisibility($review, $visible);   // also refreshes the store / shopper rating
                $record->unsetRelation('review');

                Notification::make()
                    ->title($visible ? __('admin.reviews.shown_done') : __('admin.reviews.hidden_done'))
                    ->success()
                    ->send();
            });
    }
}
