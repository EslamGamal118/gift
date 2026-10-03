<?php

namespace App\Http\Controllers\Api\V1\Shopper;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Shopper\ShopperStatisticsResource;
use App\Services\ShopperStatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopperStatisticsController extends Controller
{
    public function __construct(protected ShopperStatisticsService $statistics) {}

    /**
     * GET /api/v1/shopper/statistics
     *
     * Weekly earnings with their growth and day-by-day trend (Saturday to
     * Friday), completed / active orders, acceptance rate, average shopping
     * time, category shares and the customers' rating.
     */
    public function __invoke(Request $request): JsonResponse
    {
        return ApiResponse::success('messages.success', new ShopperStatisticsResource($this->statistics->build($request->user())));
    }
}
