<?php

namespace App\Http\Controllers\Api\V1\Shopper;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Shopper\ShopperHomeResource;
use App\Services\ShopperHomeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopperHomeController extends Controller
{
    public function __construct(protected ShopperHomeService $home) {}

    /**
     * GET /api/v1/shopper/home?orders_limit=10
     *
     * The shopper's figures (active tasks with weekly growth, new / completed
     * orders, acceptance rate, earnings) and the newest orders waiting for them.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate(['orders_limit' => ['nullable', 'integer', 'between:1,20']]);
        $shopper   = $request->user();

        return ApiResponse::success('messages.success', new ShopperHomeResource([
            'stats'      => $this->home->stats($shopper),
            'new_orders' => $this->home->newOrders($shopper, (int) ($validated['orders_limit'] ?? 10)),
        ]));
    }
}
