<?php

namespace App\Services;

use App\Exceptions\ReviewException;
use App\Models\CustomOrder;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\ShopperProfile;
use App\Models\StoreReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * One review per finished order, for both kinds of order:
 *
 *  - a store order once delivered: `store_rating` also becomes the order's
 *    store review (store_reviews, which keeps the store's rating and its
 *    reviews tab), `products_rating` feeds each purchased product's rating;
 *  - a custom order once completed: `store_rating` rates the personal shopper
 *    (shopper_profiles.rating_avg / rating_count).
 */
class ReviewService
{
    /**
     * @param  string  $type  Review::TYPE_ORDER | Review::TYPE_CUSTOM_ORDER
     * @param  array{store_rating: int, store_comment: ?string, products_rating: int, products_comment: ?string}  $data
     *
     * @throws ReviewException  not finished yet, or already reviewed
     */
    public function submit(User $customer, string $type, int $orderId, array $data): Review
    {
        return DB::transaction(function () use ($customer, $type, $orderId, $data) {
            $order = $this->findForCustomer($customer, $type, $orderId);

            if (! $this->isReviewable($order)) {
                throw ReviewException::notReviewable($order->status);
            }

            if ($order->review()->exists()) {
                throw ReviewException::alreadyReviewed();
            }

            try {
                /** @var Review $review */
                $review = $order->review()->create(['user_id' => $customer->id] + $data);
            } catch (UniqueConstraintViolationException) {
                throw ReviewException::alreadyReviewed();   // a concurrent submission won
            }

            $order instanceof Order ? $this->applyToStoreOrder($order, $review) : $this->applyToCustomOrder($order);

            return $review->setRelation('reviewable', $order);
        });
    }

    /**
     * The customer's own order, locked (another customer's order is a 404).
     */
    protected function findForCustomer(User $customer, string $type, int $orderId): Order|CustomOrder
    {
        /** @var class-string<Order|CustomOrder> $class */
        $class = Review::TYPES[$type];

        return $class::query()->where('user_id', $customer->id)->lockForUpdate()->findOrFail($orderId);
    }

    protected function isReviewable(Order|CustomOrder $order): bool
    {
        return $order instanceof Order
            ? $order->isDelivered()
            : $order->status === CustomOrder::STATUS_COMPLETED;
    }

    /**
     * The store review of the order (its aggregate is recalculated by
     * StoreReview itself) and the rating of every product bought.
     */
    protected function applyToStoreOrder(Order $order, Review $review): void
    {
        if ($store = $order->storeProfile) {
            StoreReview::query()->updateOrCreate(
                ['order_id' => $order->id],
                [
                    'store_profile_id' => $store->id,
                    'user_id'          => $review->user_id,
                    'rating'           => $review->store_rating,
                    'comment'          => $review->store_comment,
                ],
            );
        }

        $order->items()->whereNotNull('product_id')->distinct()->pluck('product_id')
            ->each(fn (int $productId) => $this->recalculateProduct($productId));
    }

    protected function applyToCustomOrder(CustomOrder $order): void
    {
        if ($order->shopper_id) {
            $this->recalculateShopper($order->shopper_id);
        }
    }

    /**
     * A product's rating: the products rating of every reviewed order it was in.
     */
    protected function recalculateProduct(int $productId): void
    {
        $this->storeAggregate(
            Product::query()->whereKey($productId),
            Review::query()
                ->where('reviewable_type', Review::TYPE_ORDER)
                ->whereIn('reviewable_id', fn ($query) => $query->select('order_id')->from('order_items')->where('product_id', $productId)),
            'products_rating',
        );
    }

    /**
     * A personal shopper's rating: the shopper rating of their reviewed custom orders.
     */
    protected function recalculateShopper(int $shopperId): void
    {
        $this->storeAggregate(
            ShopperProfile::query()->where('user_id', $shopperId),
            Review::query()
                ->where('reviewable_type', Review::TYPE_CUSTOM_ORDER)
                ->whereIn('reviewable_id', fn ($query) => $query->select('id')->from('custom_orders')->where('shopper_id', $shopperId)),
            'store_rating',
        );
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Model>  $target
     * @param  \Illuminate\Database\Eloquent\Builder<Review>  $reviews
     */
    protected function storeAggregate($target, $reviews, string $column): void
    {
        $stats = $reviews->selectRaw("COUNT(*) as total, COALESCE(AVG({$column}), 0) as average")->toBase()->first();

        $target->update([
            'rating_avg'   => round((float) $stats->average, 2),
            'rating_count' => (int) $stats->total,
        ]);
    }
}
