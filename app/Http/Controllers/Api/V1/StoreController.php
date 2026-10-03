<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductsRequest;
use App\Http\Requests\Catalog\StoreReviewsRequest;
use App\Http\Requests\Catalog\StoreShowRequest;
use App\Http\Resources\ProductListingResource;
use App\Http\Resources\StoreDetailsResource;
use App\Http\Resources\StoreReviewResource;
use App\Http\Resources\StoreTabResource;
use App\Services\StoreShowService;
use Illuminate\Http\JsonResponse;
class StoreController extends Controller
{
    use PaginatesResults;

    public function __construct(
        protected StoreShowService $stores,
    ) {
    }

    /**
     * GET /api/v1/stores/{store}?latitude=..&longitude=..&tab=best_sellers&sort=..&page=..
     *
     * Everything the screen needs on first paint, nothing more: the store
     * header with `is_favorite` and its delivery fee / time / distance,
     * filter tabs, first page of products (viewer's favorites first for
     * ranking sorts) and the latest reviews.
     */
    public function show(StoreShowRequest $request, int $store): JsonResponse
    {
        $store    = $this->stores->find($store, $request->location());
        $filters  = $request->filters();
        $perPage  = $request->filled('per_page')
            ? $this->perPage($request)
            : (int) config('stores.show.products_per_page', 10);

        $products = $this->stores->products($store, $filters, $perPage);

        return ApiResponse::success('messages.success', (new StoreDetailsResource($store))->resolve() + [
            'tabs'     => StoreTabResource::collection($this->stores->tabs($store)),
            'products' => [
                'tab'     => $request->tab(),
                'filters' => (object) $filters,
            ] + $this->paginated($products, ProductListingResource::collection($products)),
            'reviews'  => [
                'total' => (int) $store->rating_count,
                'items' => StoreReviewResource::collection($this->stores->recentReviews($store)),
            ],
        ]);
    }

    /**
     * GET /api/v1/stores/{store}/products?tab=..&search=..&sort=..&page=..
     */
    public function products(StoreProductsRequest $request, int $store): JsonResponse
    {
        $store    = $this->stores->find($store);
        $filters  = $request->filters();
        $products = $this->stores->products($store, $filters, $this->perPage($request));

        return ApiResponse::success('messages.success', [
            'store_id' => $store->id,
            'tab'      => $request->tab(),
            'filters'  => (object) $filters,
            'tabs'     => StoreTabResource::collection($this->stores->tabs($store)),
        ] + $this->paginated($products, ProductListingResource::collection($products)));
    }

    /**
     * GET /api/v1/stores/{store}/reviews?rating=5&page=..
     */
    public function reviews(StoreReviewsRequest $request, int $store): JsonResponse
    {
        $store   = $this->stores->find($store);
        $reviews = $this->stores->reviews($store, $request->rating(), $this->perPage($request));

        return ApiResponse::success('messages.success', [
            'store_id' => $store->id,
            'rating'   => $this->stores->ratingSummary($store),
            'filter'   => ['rating' => $request->rating()],
        ] + $this->paginated($reviews, StoreReviewResource::collection($reviews)));
    }
}
