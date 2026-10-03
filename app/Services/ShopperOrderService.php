<?php

namespace App\Services;

use App\Exceptions\CustomOrderException;
use App\Models\CustomOrder;
use App\Models\CustomOrderAlternative;
use App\Models\CustomOrderItem;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The personal shopper's own orders (custom orders assigned to them), for the
 * shopper app: active / history tabs, a status badge filter and keyword search;
 * moving an order along its workflow and suggesting alternatives for
 * unavailable items, each followed by a notification to the customer.
 *
 * Drafts are never shown: the customer may still change the shopper, and the
 * shopper is only notified once the order is confirmed (status `pending`).
 */
class ShopperOrderService
{
    public const TAB_ACTIVE = 'active';

    public const TAB_HISTORY = 'history';

    public const TABS = [self::TAB_ACTIVE, self::TAB_HISTORY];

    /**
     * Shopper-facing status keys (badges) => stored status.
     *
     * @var array<string, string>
     */
    public const STATUS_KEYS = [
        'new'         => CustomOrder::STATUS_PENDING,
        'accepted'    => CustomOrder::STATUS_ACCEPTED,
        'in_progress' => CustomOrder::STATUS_IN_PROGRESS,
        'waiting_for_alternative' => CustomOrder::STATUS_WAITING_FOR_ALTERNATIVE,
        'waiting_for_payment'     => CustomOrder::STATUS_WAITING_FOR_PAYMENT,
        'completed'   => CustomOrder::STATUS_COMPLETED,
        'cancelled'   => CustomOrder::STATUS_CANCELLED,
    ];

    /**
     * Other spellings accepted by `?status=` (the stored name, or app wording).
     *
     * @var array<string, string>
     */
    public const STATUS_ALIASES = [
        'pending'   => 'new',
        'confirmed' => 'accepted',
        'canceled'  => 'cancelled',
    ];

    /**
     * @var array<string, list<string>>
     */
    public const TAB_STATUSES = [
        self::TAB_ACTIVE  => CustomOrder::ACTIVE_STATUSES,
        self::TAB_HISTORY => CustomOrder::HISTORY_STATUSES,
    ];

    /**
     * Statuses the shopper sets, in workflow order, with the timestamp each one stamps.
     * pending -> accepted (قبول الطلب) -> in_progress (بدء التسوق) -> waiting_for_payment (تم الشراء)
     * The order is `completed` by the customer's payment, not by the shopper.
     *
     * @var array<string, string>
     */
    public const WORKFLOW = [
        CustomOrder::STATUS_ACCEPTED            => 'accepted_at',
        CustomOrder::STATUS_IN_PROGRESS         => 'started_at',
        CustomOrder::STATUS_WAITING_FOR_PAYMENT => 'purchased_at',
    ];

    /**
     * Every status the shopper may set: the workflow steps, plus `cancelled`
     * to decline a new order or give up one already accepted / in progress.
     *
     * @var list<string>
     */
    public const ACTION_STATUSES = [
        CustomOrder::STATUS_ACCEPTED,
        CustomOrder::STATUS_IN_PROGRESS,
        CustomOrder::STATUS_WAITING_FOR_PAYMENT,
        CustomOrder::STATUS_CANCELLED,
    ];

    /**
     * Statuses during which the shopper may suggest alternatives (also while
     * the customer is still reviewing an earlier one).
     *
     * @var list<string>
     */
    public const SHOPPING_STATUSES = [CustomOrder::STATUS_ACCEPTED, CustomOrder::STATUS_IN_PROGRESS, CustomOrder::STATUS_WAITING_FOR_ALTERNATIVE];

    /**
     * Statuses during which the shopper may submit (or replace, until paid)
     * the purchase invoice; submitting it while shopping also marks the
     * order purchased (waiting for payment).
     *
     * @var list<string>
     */
    public const INVOICE_STATUSES = [CustomOrder::STATUS_IN_PROGRESS, CustomOrder::STATUS_WAITING_FOR_PAYMENT];

    public function __construct(
        protected NotificationService $notifications,
        protected CustomOrderPricing $pricing,
    ) {}

    /**
     * @param  array{tab?: ?string, status?: ?string, search?: ?string}  $filters  `status` is a key of STATUS_KEYS
     */
    public function paginate(User $shopper, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->query($shopper, $filters)
            ->with('user:id,name,avatar')
            ->withCount('items')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Orders per tab and per status key for the current search (tab / status
     * filters ignored), for the tab and chip badges.
     *
     * @param  array{search?: ?string}  $filters
     * @return array{active: int, history: int, all: int, by_status: array<string, int>}
     */
    public function counts(User $shopper, array $filters = []): array
    {
        $byStatus = $this->query($shopper, ['search' => $filters['search'] ?? null])
            ->toBase()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'active'    => (int) $byStatus->only(self::TAB_STATUSES[self::TAB_ACTIVE])->sum(),
            'history'   => (int) $byStatus->only(self::TAB_STATUSES[self::TAB_HISTORY])->sum(),
            'all'       => (int) $byStatus->sum(),
            'by_status' => array_map(fn (string $status) => (int) ($byStatus[$status] ?? 0), self::STATUS_KEYS),
        ];
    }

    /**
     * The shopper's order for an action, or 404 (another shopper's order, a
     * draft not confirmed yet, or an unknown id are all "not found").
     */
    public function findForShopper(User $shopper, int $id): CustomOrder
    {
        return CustomOrder::query()
            ->forShopper($shopper->id)
            ->where('status', '!=', CustomOrder::STATUS_DRAFT)
            ->findOrFail($id);
    }

    /**
     * The shopper's order with everything its details screen shows: customer,
     * items with their reference images and suggested alternatives.
     */
    public function details(User $shopper, int $id): CustomOrder
    {
        return $this->findForShopper($shopper, $id)
            ->load(['user:id,name,phone,avatar', 'items.media', 'items.alternatives', 'pickupAddress'])
            ->loadCount('items');
    }

    /**
     * The step the shopper can take next (accepted / in_progress / completed), or null.
     */
    public static function nextStatus(CustomOrder $order): ?string
    {
        foreach (array_keys(self::WORKFLOW) as $status) {
            if ($order->canTransitionTo($status)) {
                return $status;
            }
        }

        return null;
    }

    /**
     * Move the order one step along the workflow, stamp the step's timestamp
     * (and the amount actually spent when purchased), then notify the customer.
     *
     * `cancelled` declines / gives up the order from any unfinished status
     * (pending, accepted, in progress), recording the shopper and the reason.
     *
     * Purchased (`waiting_for_payment`, تم الشراء): `$itemPrices` (item id =>
     * price paid per unit) is saved on the items; once every item is priced
     * the final amount is their total (computed, never sent by the client).
     * With `$invoice` (the invoice form) the invoice is stored and the order
     * priced as by submitInvoice(), in the same transaction; an order already
     * purchased then just takes the invoice. The customer is told to pay; the
     * payment completes the order. The purchased order comes back with its items.
     *
     * @param  array<int, float>  $itemPrices
     * @param  array{image: UploadedFile, pickup_address_id: ?int, pickup_address: ?array<string, mixed>, shopper_fees: float, item_prices: array<int, float>}|null  $invoice
     *
     * @throws CustomOrderException  the step does not follow the current status
     */
    public function updateStatus(User $shopper, CustomOrder $order, string $status, ?string $reason = null, array $itemPrices = [], ?array $invoice = null): CustomOrder
    {
        $previous = $order->status;
        $path     = $invoice ? $this->storeInvoice($order, $invoice['image']) : null;
        $replaced = null;

        try {
            $order = DB::transaction(function () use ($shopper, $order, $status, $reason, $itemPrices, $invoice, $path, &$previous, &$replaced) {
                $order    = $this->lock($shopper, $order);
                $previous = $order->status;

                if ($status === CustomOrder::STATUS_CANCELLED) {
                    if (! $order->isCancellable()) {
                        throw CustomOrderException::notCancellable($order->status);
                    }

                    $order->forceFill([
                        'status'              => CustomOrder::STATUS_CANCELLED,
                        'cancelled_at'        => now(),
                        'cancelled_by'        => CustomOrder::ACTOR_SHOPPER,
                        'cancellation_reason' => $reason !== null ? trim($reason) : null,
                    ])->save();

                    return $order;
                }

                // Step 2 sent to the status endpoint after "purchased": only the invoice is new
                $invoiceOnly = $invoice !== null
                    && $status === CustomOrder::STATUS_WAITING_FOR_PAYMENT
                    && $order->status === CustomOrder::STATUS_WAITING_FOR_PAYMENT;

                if (! $invoiceOnly) {
                    if (! isset(self::WORKFLOW[$status]) || ! $order->canTransitionTo($status)) {
                        throw CustomOrderException::invalidTransition($order->status, $status);
                    }

                    $order->forceFill(['status' => $status, self::WORKFLOW[$status] => now()]);
                }

                if (($invoice !== null || $itemPrices !== []) && $order->isPaid()) {
                    throw CustomOrderException::alreadyPaid();
                }

                if ($invoice !== null) {
                    $replaced = $this->applyInvoice($shopper, $order, $invoice, $path);
                } elseif ($status === CustomOrder::STATUS_WAITING_FOR_PAYMENT) {
                    $subtotal = $this->pricing->itemsSubtotal($this->recordItemPrices($order, $itemPrices));

                    if ($subtotal !== null && $order->total_amount !== null) {
                        // Invoice already submitted while in progress: re-price it (VAT included)
                        $this->pricing->apply($order, $subtotal, (float) $order->shopper_fees, (float) $order->delivery_fee);
                    } elseif ($subtotal !== null) {
                        $order->final_amount = round($subtotal + (float) ($order->shopper_fees ?? 0), 2);
                    }
                }

                $order->save();

                return $order;
            });
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }

            throw $e;
        }

        $this->deleteInvoiceFile($replaced);

        if ($order->status !== $previous) {
            $this->notifications->notifyCustomOrderStatus($order, $previous);
        }

        $order->load('user:id,name,avatar')->loadCount('items');

        if ($order->status === CustomOrder::STATUS_WAITING_FOR_PAYMENT) {
            $order->load(['items', 'pickupAddress']);
        }

        return $order;
    }

    /**
     * Save the price paid per unit on the order's items, returning all of them.
     *
     * @param  array<int, float>  $itemPrices  item id => unit price
     * @return Collection<int, CustomOrderItem>
     */
    protected function recordItemPrices(CustomOrder $order, array $itemPrices): Collection
    {
        $items = $order->items()->get();

        foreach ($items as $item) {
            if (array_key_exists($item->id, $itemPrices)) {
                $item->forceFill(['unit_price' => round($itemPrices[$item->id], 2)])->save();
            }
        }

        return $items;
    }

    /**
     * Suggest a product instead of an unavailable item of the order (only while
     * shopping: accepted / in progress / already waiting for an alternative),
     * put the order in `waiting_for_alternative`, then ask the customer to
     * review the suggestion (in-app + push). The alternative comes back with
     * its item and the updated order.
     *
     * @param  array{item_id: int, product_name: string, price: float|string, reason: string}  $data
     *
     * @throws CustomOrderException
     */
    public function suggestAlternative(User $shopper, CustomOrder $order, array $data, ?UploadedFile $image = null): CustomOrderAlternative
    {
        $path = $image?->store(
            trim((string) config('custom_orders.items.media_directory', 'custom-orders'), '/')."/{$order->id}/alternatives",
            'public',
        );

        try {
            $alternative = DB::transaction(function () use ($shopper, $order, $data, $path) {
                $order = $this->lock($shopper, $order);

                if (! in_array($order->status, self::SHOPPING_STATUSES, true)) {
                    throw CustomOrderException::alternativesClosed($order->status);
                }

                $item = $order->items()->findOrFail((int) $data['item_id']);

                $alternative = CustomOrderAlternative::query()->create([
                    'custom_order_id'      => $order->id,
                    'custom_order_item_id' => $item->id,
                    'shopper_id'           => $shopper->id,
                    'product_name'         => trim($data['product_name']),
                    'price'                => round((float) $data['price'], 2),
                    'currency'             => $order->currency,
                    'reason'               => trim($data['reason']),
                    'image_disk'           => 'public',
                    'image_path'           => $path ?: null,
                    'status'               => CustomOrderAlternative::STATUS_PENDING,
                ]);

                if (! $order->isWaitingForAlternative()) {
                    $order->forceFill(['status' => CustomOrder::STATUS_WAITING_FOR_ALTERNATIVE])->save();
                }

                return $alternative->setRelation('customOrder', $order);
            });
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }

            throw $e;
        }

        $this->notifications->notifyAlternativeSuggested($alternative);

        return $alternative->load('item');
    }

    /**
     * Store the purchase invoice with the actual price paid per unit of each
     * item, the pickup address and the shopper's fees, then price the order
     * (see applyInvoice()). While shopping it also marks the order purchased
     * (waiting_for_payment) and tells the customer to pay; once purchased it
     * replaces the previous invoice, until paid.
     *
     * @param  array{image: UploadedFile, pickup_address_id: ?int, pickup_address: ?array<string, mixed>, shopper_fees: float, item_prices: array<int, float>}  $invoice
     *
     * @throws CustomOrderException  the order is not in progress / completed
     */
    public function submitInvoice(User $shopper, CustomOrder $order, array $invoice): CustomOrder
    {
        $path     = $this->storeInvoice($order, $invoice['image']);
        $replaced = null;
        $previous = $order->status;

        try {
            $order = DB::transaction(function () use ($shopper, $order, $invoice, $path, &$replaced, &$previous) {
                $order    = $this->lock($shopper, $order);
                $previous = $order->status;

                if (! in_array($order->status, self::INVOICE_STATUSES, true)) {
                    throw CustomOrderException::invoiceClosed($order->status);
                }

                if ($order->isPaid()) {
                    throw CustomOrderException::alreadyPaid();
                }

                $replaced = $this->applyInvoice($shopper, $order, $invoice, $path);

                // The invoice means the items were bought: the customer pays next
                if ($order->status === CustomOrder::STATUS_IN_PROGRESS) {
                    $order->forceFill(['status' => CustomOrder::STATUS_WAITING_FOR_PAYMENT, 'purchased_at' => now()]);
                }

                $order->save();

                return $order;
            });
        } catch (Throwable $e) {
            Storage::disk('public')->delete($path);

            throw $e;
        }

        $this->deleteInvoiceFile($replaced);

        if ($order->status !== $previous) {
            $this->notifications->notifyCustomOrderStatus($order, $previous);
        }

        return $order->load(['user:id,name,phone,avatar', 'items.media', 'items.alternatives', 'pickupAddress'])->loadCount('items');
    }

    protected function storeInvoice(CustomOrder $order, UploadedFile $image): string
    {
        return $image->store(
            trim((string) config('custom_orders.items.media_directory', 'custom-orders'), '/')."/{$order->id}/invoices",
            'public',
        );
    }

    /**
     * Fill (not save) the invoice on the locked order and save the item
     * prices, then price the order on the server (CustomOrderPricing):
     *   final_amount = sum(unit_price x quantity) + shopper_fees
     *   tax_amount   = VAT on final_amount + delivery_fee
     *   total_amount = final_amount + delivery_fee + tax_amount
     * Items left out keep any price recorded earlier; every item must end up
     * priced (0 for one not bought).
     * The pickup address is one of the shopper's saved addresses, or a new
     * one saved for them from the form; the customer's delivery address is
     * left as it is.
     *
     * @param  array{pickup_address_id: ?int, pickup_address: ?array<string, mixed>, shopper_fees: float, item_prices: array<int, float>}  $invoice
     * @return array{0: string, 1: string}|null  the invoice file it replaces (disk, path)
     *
     * @throws CustomOrderException  the pickup address is no longer one of the shopper's
     * @throws ValidationException  an item of the order has no price yet
     */
    protected function applyInvoice(User $shopper, CustomOrder $order, array $invoice, string $path): ?array
    {
        $pickup = $this->pickupAddress($shopper, $invoice);

        $subtotal = $this->pricing->itemsSubtotal($this->recordItemPrices($order, $invoice['item_prices']))
            ?? throw ValidationException::withMessages(['items' => __('custom_orders.items_unpriced')]);

        $replaced = $order->invoice_path ? [$order->invoice_disk ?: 'public', $order->invoice_path] : null;

        $this->pricing->apply($order, $subtotal, $invoice['shopper_fees'], (float) config('custom_orders.delivery.fee', 0));

        $order->forceFill([
            'pickup_address_id'    => $pickup->id,
            'invoice_disk'         => 'public',
            'invoice_path'         => $path,
            'invoice_submitted_at' => now(),
        ]);

        return $replaced;
    }

    /**
     * The shopper's saved address chosen as pickup, or a new one created for
     * them from `pickup_address` (their default only if it is their first,
     * as with POST /addresses).
     *
     * @param  array{pickup_address_id: ?int, pickup_address: ?array<string, mixed>}  $invoice
     *
     * @throws CustomOrderException
     */
    protected function pickupAddress(User $shopper, array $invoice): UserAddress
    {
        if ($invoice['pickup_address_id'] !== null) {
            return $shopper->addresses()->find($invoice['pickup_address_id']) ?? throw CustomOrderException::addressNotFound();
        }

        return $shopper->addresses()->create($invoice['pickup_address'] + [
            'phone'      => $shopper->phone,
            'is_default' => $shopper->addresses()->doesntExist(),
        ]);
    }

    /**
     * @param  array{0: string, 1: string}|null  $file  (disk, path)
     */
    protected function deleteInvoiceFile(?array $file): void
    {
        if ($file) {
            Storage::disk($file[0])->delete($file[1]);
        }
    }

    public static function statusKey(string $status): ?string
    {
        return array_search($status, self::STATUS_KEYS, true) ?: null;
    }

    public static function tabFor(string $status): ?string
    {
        foreach (self::TAB_STATUSES as $tab => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $tab;
            }
        }

        return null;
    }

    /**
     * Re-read the order under a row lock, still assigned to this shopper.
     */
    protected function lock(User $shopper, CustomOrder $order): CustomOrder
    {
        return CustomOrder::query()->forShopper($shopper->id)->lockForUpdate()->findOrFail($order->getKey());
    }

    /**
     * The shopper's non-draft orders, narrowed by tab, then status (a status
     * outside the tab gives an empty list), then keyword.
     *
     * @param  array{tab?: ?string, status?: ?string, search?: ?string}  $filters
     */
    protected function query(User $shopper, array $filters): Builder
    {
        $statuses = array_merge(...array_values(self::TAB_STATUSES));

        if ($tab = $filters['tab'] ?? null) {
            $statuses = self::TAB_STATUSES[$tab];
        }

        if ($key = $filters['status'] ?? null) {
            $statuses = array_values(array_intersect($statuses, [self::STATUS_KEYS[$key]]));
        }

        return CustomOrder::query()
            ->forShopper($shopper->id)
            ->whereIn('status', $statuses)
            ->matchingKeyword($filters['search'] ?? null, CustomOrder::KEYWORD_VIEWER_SHOPPER);
    }
}
