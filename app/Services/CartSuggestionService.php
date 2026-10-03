<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "You may also like" under the cart.
 *
 *  - empty cart: the best sellers of every store
 *  - otherwise: the best sellers of the stores already in the cart, split
 *    evenly between them (up to ceil(limit / stores) each) and interleaved,
 *    store 1's first, store 2's first, ..., then each one's second, ...
 *
 * Always: visible, not expired, in stock, not already in the cart, and not
 * online gifts (those are bought through the gift flow, not the cart).
 * Best seller = Product::bestSellers() (units on paid orders, then rating).
 */
class CartSuggestionService
{
    public const SOURCE_CART_STORES = 'cart_stores';

    public const SOURCE_BEST_SELLERS = 'best_sellers';

    public function __construct(protected CartService $carts) {}

    /**
     * @return array{source: string, store_ids: list<int>, products: Collection<int, Product>}
     */
    public function forUser(User $user, ?int $limit = null): array
    {
        $limit ??= (int) config('checkout.cart.suggestions_limit', 10);

        $cart = $this->carts->forUser($user, withRelations: false);
        $lines = $cart->items()->join('products', 'products.id', '=', 'cart_items.product_id')
            ->orderBy('cart_items.id')
            ->get(['cart_items.product_id', 'products.store_id']);

        $inCart = $lines->pluck('product_id')->unique()->values()->all();
        $storeIds = $lines->pluck('store_id')->map(fn ($id) => (int) $id)->unique()->values()->all();   // in the order they were added

        $products = $storeIds === []
            ? $this->candidates($inCart)->bestSellers()->limit($limit)->get()
            : $this->fromStores($storeIds, $inCart, $limit);

        return [
            'source' => $storeIds === [] ? self::SOURCE_BEST_SELLERS : self::SOURCE_CART_STORES,
            'store_ids' => $storeIds,
            'products' => $products->load('storeProfile'),
        ];
    }

    /**
     * Each store's best sellers in a single query: rank them per store with
     * ROW_NUMBER(), keep the top `perStore`, interleave by rank then by the
     * stores' order in the cart.
     *
     * @param  list<int>  $storeIds
     * @param  list<int>  $inCart
     * @return Collection<int, Product>
     */
    protected function fromStores(array $storeIds, array $inCart, int $limit): Collection
    {
        $perStore = (int) ceil($limit / count($storeIds));

        $scored = $this->candidates($inCart)
            ->whereIn('products.store_id', $storeIds)
            ->bestSellers()
            ->reorder()
            ->toBase();

        $ranked = DB::query()->fromSub($scored, 'scored')
            ->select('scored.*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY scored.store_id ORDER BY COALESCE(scored.sold_quantity, 0) DESC, scored.rating_count DESC, scored.rating_avg DESC, scored.id DESC) AS store_rank');

        $storeOrder = implode(',', array_map('intval', $storeIds));

        return Product::query()
            ->fromSub($ranked, 'products')
            ->where('store_rank', '<=', $perStore)
            ->orderBy('store_rank')
            ->orderByRaw("FIELD(products.store_id, {$storeOrder})")
            ->limit($limit)
            ->get();
    }

    /**
     * Products a customer may be offered from the cart screen.
     *
     * @param  list<int>  $inCart
     */
    protected function candidates(array $inCart): Builder
    {
        return Product::query()
            ->select('products.*')
            ->visible()
            ->notExpired()
            ->inStock()
            ->whereDoesntHave('category', fn (Builder $q) => $q->where('is_special', true))
            ->when($inCart !== [], fn (Builder $q) => $q->whereNotIn('products.id', $inCart));
    }
}
