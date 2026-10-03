<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductShowRequest;
use App\Http\Resources\ProductDetailsResource;
use App\Services\ProductShowService;
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
    public function __construct(
        protected ProductShowService $products,
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
}
