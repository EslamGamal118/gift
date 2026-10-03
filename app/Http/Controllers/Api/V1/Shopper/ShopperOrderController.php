<?php

namespace App\Http\Controllers\Api\V1\Shopper;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shopper\ShopperOrderIndexRequest;
use App\Http\Requests\Shopper\ShopperOrderShowRequest;
use App\Http\Requests\Shopper\SubmitInvoiceRequest;
use App\Http\Requests\Shopper\SuggestAlternativeRequest;
use App\Http\Requests\Shopper\UpdateShopperOrderStatusRequest;
use App\Http\Resources\CustomOrder\CustomOrderAlternativeResource;
use App\Http\Resources\Shopper\ShopperOrderDetailResource;
use App\Http\Resources\Shopper\ShopperOrderResource;
use App\Services\ShopperOrderService;
use Illuminate\Http\JsonResponse;

/**
 * Personal shopper app: the custom orders assigned to the signed-in shopper,
 * their workflow (accept -> start shopping -> purchased) and alternatives for
 * unavailable items. Every action notifies the customer (in-app + push).
 */
class ShopperOrderController extends Controller
{
    use PaginatesResults;

    public function __construct(protected ShopperOrderService $orders) {}

    /**
     * GET /api/v1/shopper/orders?tab=active|history&status=&search=&page=&per_page=
     */
    public function index(ShopperOrderIndexRequest $request): JsonResponse
    {
        $shopper   = $request->user();
        $filters   = $request->filters();
        $paginator = $this->orders->paginate($shopper, $filters, $this->perPage($request));

        return ApiResponse::success('messages.success', [
            'filters' => $filters,
            'counts'  => $this->orders->counts($shopper, $filters),
        ] + $this->paginated($paginator, ShopperOrderResource::collection($paginator)));
    }

    /**
     * GET /api/v1/shopper/orders/{customOrder}
     *
     * The order with its customer, delivery details, items (expected prices,
     * reference images, suggested alternatives), timeline and the next step.
     * 403 for non-shoppers, 404 for another shopper's order or a draft.
     */
    public function show(ShopperOrderShowRequest $request): JsonResponse
    {
        $order = $this->orders->details($request->user(), (int) $request->route('customOrder'));

        return ApiResponse::success('messages.success', new ShopperOrderDetailResource($order));
    }

    /**
     * POST /api/v1/shopper/orders/{customOrder}/status  (JSON or multipart)
     * { status: accepted|in_progress|purchased|cancelled, cancellation_reason?, items?: [{ id, unit_price }],
     *   invoice_image?, pickup_address_id? | pickup_address[...]?, shopper_fees? }
     *
     * One step along pending -> accepted -> in_progress -> waiting_for_payment
     * (purchased; the customer's payment then completes the order), or
     * `cancelled` (reject / give up, reason required) from any unfinished
     * status; any other move is a 409. Stamps accepted_at / started_at /
     * purchased_at, or cancelled_at + cancelled_by = shopper + the reason.
     * The customer is notified of every status change.
     *
     * Completing takes two steps in the app: "تم الشراء" sends the status
     * alone; "تأكيد وإرسال" sends the invoice form (invoice_image,
     * pickup_address_id | pickup_address, shopper_fees, items), here with
     * status = purchased or to /submit-invoice-prices. The response lists the
     * purchased items with unit and line prices, the invoice and the price
     * breakdown once submitted.
     */
    public function updateStatus(UpdateShopperOrderStatusRequest $request): JsonResponse
    {
        $order = $this->orders->updateStatus(
            $request->user(),
            $request->order(),
            $request->status(),
            $request->cancellationReason(),
            $request->itemPrices(),
            $request->invoice(),
        );

        return ApiResponse::success(
            $request->withInvoice() ? 'custom_orders.invoice_submitted' : 'custom_orders.status_updated',
            new ShopperOrderResource($order),
        );
    }

    /**
     * POST /api/v1/shopper/orders/{customOrder}/submit-invoice-prices
     * (multipart: invoice_image, pickup_address_id | pickup_address[...], shopper_fees, items[i][id], items[i][unit_price])
     *
     * Step 2 on its own: stores the purchase invoice, the price paid per unit
     * of each item, the pickup address (one of the shopper's, or a new one
     * saved for them) and the shopper's fees, computes the final amount
     * (items + shopper fees) and returns the order details with its items and
     * the price breakdown (items subtotal + shopper fees + delivery fee = total). Only
     * while in progress or waiting for payment (409 otherwise; and never once paid).
     */
    public function submitInvoice(SubmitInvoiceRequest $request): JsonResponse
    {
        $order = $this->orders->submitInvoice($request->user(), $request->order(), $request->invoice());

        return ApiResponse::success('custom_orders.invoice_submitted', new ShopperOrderDetailResource($order));
    }

    /**
     * POST /api/v1/shopper/orders/{customOrder}/alternatives  (multipart: item_id, product_name, price, reason, image?)
     *
     * Only while the order is accepted, in progress or already waiting for an
     * alternative (409 otherwise). The order moves to `waiting_for_alternative`
     * and the customer is asked to review the suggestion; the response
     * includes the order's new status (`order.status`).
     */
    public function suggestAlternative(SuggestAlternativeRequest $request): JsonResponse
    {
        $alternative = $this->orders->suggestAlternative($request->user(), $request->order(), $request->alternative(), $request->file('image'));

        return ApiResponse::send(201, 'custom_orders.alternative_sent', new CustomOrderAlternativeResource($alternative));
    }
}
