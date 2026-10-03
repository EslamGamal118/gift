<?php

namespace App\Http\Controllers\Api\V1\Checkout;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\AddCartItemRequest;
use App\Http\Requests\Checkout\UpdateCartItemRequest;
use App\Http\Resources\Checkout\CartResource;
use App\Http\Resources\Checkout\SuggestedProductResource;
use App\Services\CartService;
use App\Services\CartSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's cart. Items may come from several stores (see CartService).
 */
class CartController extends Controller
{
    public function __construct(protected CartService $carts) {}

    /**
     * GET /api/v1/cart/suggested-products
     *
     * "You may also like" under the cart: best sellers of the stores in the
     * cart, split evenly between them (`source` = cart_stores), or of every
     * store when the cart is empty (`source` = best_sellers). Never a product
     * already in the cart.
     */
    public function suggestedProducts(Request $request, CartSuggestionService $suggestions): JsonResponse
    {
        $result = $suggestions->forUser($request->user());

        return ApiResponse::success('messages.success', [
            'source' => $result['source'],
            'items' => SuggestedProductResource::collection($result['products']),
        ]);
    }

    /**
     * GET /api/v1/cart
     */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success('messages.success', new CartResource($this->carts->forUser($request->user())));
    }

    /**
     * POST /api/v1/cart/items
     */
    public function addItem(AddCartItemRequest $request): JsonResponse
    {
        $cart = $this->carts->addItem(
            $request->user(),
            (int) $request->validated('product_id'),
            $request->quantity(),
            $request->addonIds(),
        );

        return ApiResponse::send(201, 'checkout.item_added', new CartResource($cart));
    }

    /**
     * PUT /api/v1/cart/items/{item}
     */
    public function updateItem(UpdateCartItemRequest $request, int $item): JsonResponse
    {
        $cart = $this->carts->updateItem($request->user(), $item, $request->quantity(), $request->addonIds());

        return ApiResponse::success('checkout.item_updated', new CartResource($cart));
    }

    /**
     * DELETE /api/v1/cart/items/{item}
     */
    public function removeItem(Request $request, int $item): JsonResponse
    {
        $cart = $this->carts->removeItem($request->user(), $item);

        return ApiResponse::success('checkout.item_removed', new CartResource($cart));
    }

    /**
     * DELETE /api/v1/cart
     */
    public function clear(Request $request): JsonResponse
    {
        $cart = $this->carts->clear($request->user());

        return ApiResponse::success('checkout.cart_cleared', new CartResource($cart));
    }
}
