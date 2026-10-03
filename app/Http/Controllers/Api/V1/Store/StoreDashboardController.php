<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreDashboardRequest;
use App\Http\Resources\Store\DashboardResource;
use App\Services\StoreDashboardService;
use Illuminate\Http\JsonResponse;

/**
 * Merchant home screen: live performance, summary counters, latest paid
 * orders and the notification bell (unread count). Unpaid orders never count.
 */
class StoreDashboardController extends Controller
{
    public function __construct(protected StoreDashboardService $dashboard) {}

    /**
     * GET /api/v1/store/dashboard?period=today|week|month&orders_limit=5
     */
    public function __invoke(StoreDashboardRequest $request): JsonResponse
    {
        $store = $request->user();

        return ApiResponse::success('messages.success', new DashboardResource([
            'stats' => $this->dashboard->stats($store, $request->period()),
            'recent_orders' => $this->dashboard->recentOrders($store, $request->ordersLimit()),
            'unread_count' => $this->dashboard->unreadNotificationsCount($store),
        ]));
    }
}
