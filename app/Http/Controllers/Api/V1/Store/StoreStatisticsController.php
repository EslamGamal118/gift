<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Store\StatisticsResource;
use App\Services\StoreStatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Merchant statistics screen. Paid orders only.
 */
class StoreStatisticsController extends Controller
{
    public function __construct(protected StoreStatisticsService $statistics) {}

    /**
     * GET /api/v1/store/statistics?top_products_limit=3
     *
     * This week's earnings with their growth, profit trend and daily sales
     * (Saturday to Friday), order counters and distribution, best sellers
     * and the customers' rating.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $limit = (int) ($request->validate([
            'top_products_limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ])['top_products_limit'] ?? StoreStatisticsService::TOP_PRODUCTS);

        return ApiResponse::success('messages.success', new StatisticsResource($this->statistics->build($request->user(), $limit)));
    }
}
