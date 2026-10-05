<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductReviewsRequest;
use App\Http\Requests\Catalog\ProductShowRequest;
use App\Http\Resources\ProductDetailsResource;
use App\Http\Resources\ProductReviewResource;
use App\Services\ProductShowService;
use App\Services\ReviewFeedService;
use Illuminate\Http\JsonResponse;

/**
 * Customer-facing product details screen. Public; a Bearer token only changes
 * the location fallback (saved default address) used for the distance.
 *
 * Only purchasable products are reachable (in stock, not expired, sold by an
 * approved store with an active owner); anything else is a 404.
 */
class ProductDetailsController extends Controller
{
    use PaginatesResults;

    public function __construct(
        protected ProductShowService $products,
        protected ReviewFeedService $reviews,
    ) {
    }

    /**
     * GET /api/v1/products/{product}?latitude=..&longitude=..
     *
     * Everything the screen needs on first paint: product with price and
     * rating, store card, delivery fee / time and distance,
     * optional add-ons, related products and the latest reviews.
     */
    public function show(ProductShowRequest $request, int $product): JsonResponse
    {
        $location = $request->location();
        $product  = $this->products->find($product, $location);

        $details = (new ProductDetailsResource($product))
            ->withDelivery($this->products->deliveryMetrics($product))
            ->withAddons($this->products->addons($product))
            ->withRelated($this->products->relatedProducts($product))
            ->withReviews($this->products->recentReviews($product));

        return ApiResponse::success('messages.success', $details->resolve() + [
            'location' => $location ? $location->coordinates() + ['source' => $location->source] : null,
        ]);
    }

    /**
     * GET /api/v1/products/{product}/reviews?sort=newest|latest|highest|lowest&stars=..&with_comment=1&page=..&per_page=..
     *
     * The "see all reviews" screen: rating summary with per-star breakdown and
     * one page of reviews (newest first by default) with each reviewer's
     * name and avatar. 404 whenever the product details screen would be.
     */
    public function reviews(ProductReviewsRequest $request, int $product): JsonResponse
    {
        $product = $this->products->find($product);
        $filters = $request->filters();
        $result  = $this->reviews->forProduct($product, $filters, $this->perPage($request));

        return ApiResponse::success('messages.success', [
            'product_id' => $product->id,
            'summary'    => $result['summary'],
            'filters'    => $filters,
        ] + $this->paginated($result['reviews'], ProductReviewResource::collection($result['reviews'])));
    }
}
