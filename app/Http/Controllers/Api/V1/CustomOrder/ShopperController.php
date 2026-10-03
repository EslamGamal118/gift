<?php

namespace App\Http\Controllers\Api\V1\CustomOrder;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomOrder\ShopperListRequest;
use App\Http\Resources\CustomOrder\PersonalShopperResource;
use App\Services\CustomOrderService;
use Illuminate\Http\JsonResponse;

/**
 * Personal shoppers a customer can choose from (custom order step 2).
 */
class ShopperController extends Controller
{
    use PaginatesResults;

    public function __construct(
        protected CustomOrderService $customOrders,
    ) {
    }

    /**
     * GET /api/v1/custom-orders/shoppers?category_id=&search=&available_only=1
     *     &latitude=&longitude=&within_km=&sort=rating|distance|orders|newest
     */
    public function index(ShopperListRequest $request): JsonResponse
    {
        $location = $request->location();
        $filters  = $request->filters();
        $perPage  = $request->filled('per_page')
            ? $this->perPage($request)
            : (int) config('custom_orders.shoppers.per_page', 15);

        $shoppers = $this->customOrders->shoppers($filters, $location, $perPage);

        return ApiResponse::success('messages.success', [
            'location' => $location ? $location->coordinates() + ['source' => $location->source] : null,
            'filters'  => (object) $filters,
        ] + $this->paginated($shoppers, PersonalShopperResource::collection($shoppers)));
    }
}
