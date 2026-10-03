<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gift\ClaimGiftRequest;
use App\Http\Requests\Gift\GiftCheckoutRequest;
use App\Http\Requests\Gift\GiftDetailsRequest;
use App\Http\Resources\GiftDetailsResource;
use App\Http\Resources\GiftResource;
use App\Http\Resources\ReceivedGiftResource;
use App\Models\Gift;
use App\Services\GiftService;
use App\Services\PaymentInitiationService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Online gifts: buy a special-category product for someone, and list the
 * gifts the customer sent or received.
 */
class GiftController extends Controller
{
    use PaginatesResults;

    public function __construct(
        protected GiftService $gifts,
        protected PaymentInitiationService $payments,
    ) {
    }

    /**
     * GET /api/v1/gifts/details/{product}?quantity=&addon_ids[]=&promo_code=
     *
     * Gift details / payment screen: store, gift, greeting cards and the
     * financial summary of the selection. Call again whenever the selection
     * changes; POST /gifts/checkout with the same selection charges this total.
     */
    public function details(GiftDetailsRequest $request): JsonResponse
    {
        $details = $this->gifts->details(
            $request->user(),
            $request->giftProduct(),
            $request->quantity(),
            $request->addonIds(),
            $request->promoCode(),
        );

        return ApiResponse::success('messages.success', new GiftDetailsResource($details));
    }

    /**
     * POST /api/v1/gifts/checkout
     *
     * Creates the gift and its order, then opens the gateway's hosted payment
     * page. Payment confirmation (callback / webhook) triggers the WhatsApp
     * message to the recipient and the notification to the store. If the
     * gateway refuses the session, the gift stays unpaid and can be retried
     * with POST /orders/{order_id}/pay.
     */
    public function checkout(GiftCheckoutRequest $request): JsonResponse
    {
        $data = $request->giftData();

        $gift = $this->gifts->checkout($request->user(), $data);
        $order = $gift->order;

        $result = $this->payments->start($order, $data['gateway']);

        $payload = [
            'gift' => new GiftResource($gift->load(['order.items', 'store.storeProfile'])),
            'gateway' => $data['gateway'],
            'order_id' => $order->id,
            'order_number' => $order->order_number,
        ];

        if (! $result['success']) {
            return ApiResponse::send(
                422,
                $result['message'] ?? 'checkout.gateway_error',
                $payload + array_filter(['rejection_reason' => $result['rejection_reason'] ?? null]),
                ['gateway' => $data['gateway'], 'detail' => ''],
            );
        }

        return ApiResponse::send(201, 'checkout.payment_initiated', $payload + [
            'redirect_url' => $result['redirect_url'],
            'reference' => $result['reference'] ?? null,
        ]);
    }

    /**
     * GET /api/v1/gifts/sent - gifts this customer bought, newest first.
     */
    public function sent(Request $request): JsonResponse
    {
        return $this->list($request, $request->user()->sentGifts());
    }

    /**
     * POST /api/v1/gifts/{gift}/open  ("Open my gift")
     *
     * Marks a received gift opened (no more popup) and returns its details,
     * as GET /gifts/received/{gift}.
     */
    public function open(Request $request, int $gift): JsonResponse
    {
        $this->gifts->open($request->user(), $gift);

        return ApiResponse::success('messages.success', new ReceivedGiftResource($this->gifts->receivedDetails($request->user(), $gift)));
    }

    /**
     * POST /api/v1/gifts/{gift}/dismiss  ("Not now" on the home screen popup)
     */
    public function dismiss(Request $request, int $gift): JsonResponse
    {
        $this->gifts->dismissPopup($request->user(), $gift);

        return ApiResponse::success('messages.success', ['gift_id' => $gift, 'popup_dismissed' => true]);
    }

    /**
     * GET /api/v1/gifts/received?tab=available|finished
     *
     * The "Online gifts" screen: header, its two tabs with counts and one
     * tab's gifts (available = usable; finished = redeemed or expired; no tab = all).
     */
    public function received(Request $request): JsonResponse
    {
        $tab = $request->validate([
            'tab' => ['nullable', Rule::in([Gift::TAB_AVAILABLE, Gift::TAB_FINISHED])],
        ])['tab'] ?? null;

        $result = $this->gifts->received($request->user(), $tab, $this->perPage($request));

        return ApiResponse::success('messages.success', [
            'title' => __('gifts.screen.title'),
            'tab' => $tab,
            'tabs' => array_map(fn (string $key) => [
                'key' => $key,
                'label' => __('gifts.screen.tabs.'.$key),
                'count' => $result['counts'][$key],
            ], [Gift::TAB_AVAILABLE, Gift::TAB_FINISHED]),
        ] + $this->paginated($result['gifts'], ReceivedGiftResource::collection($result['gifts'])));
    }

    /**
     * POST /api/v1/gifts/claim  { code }  ("Claim a gift" on the Online gifts screen)
     *
     * The 6-digit code texted to the phone the gift was sent to attaches it to
     * this account (for a recipient registered with another phone). 200 with
     * the gift, now in "available"; 422 for a wrong code, 409 when another
     * account already claimed it or it expired.
     */
    public function claim(ClaimGiftRequest $request): JsonResponse
    {
        $gift = $this->gifts->claimWithCode($request->user(), $request->code());

        return ApiResponse::success('gifts.claim.done', new ReceivedGiftResource($gift));
    }

    /**
     * GET /api/v1/gifts/received/{gift}  (gift details: QR code, store, sender, message, expiry)
     */
    public function show(Request $request, int $gift): JsonResponse
    {
        return ApiResponse::success('messages.success', new ReceivedGiftResource($this->gifts->receivedDetails($request->user(), $gift)));
    }

    protected function list(Request $request, HasMany $query): JsonResponse
    {
        $paginator = $query
            ->with(['order.items', 'store.storeProfile', 'sender:id,name'])
            ->latest('id')
            ->paginate($this->perPage($request));

        return ApiResponse::success('messages.success', $this->paginated($paginator, GiftResource::collection($paginator)));
    }
}
