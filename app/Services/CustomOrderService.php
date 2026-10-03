<?php

namespace App\Services;

use App\Exceptions\CustomOrderException;
use App\Models\CustomOrder;
use App\Models\CustomOrderItem;
use App\Models\DeliverySlot;
use App\Models\ShopperProfile;
use App\Models\User;
use App\Models\UserAddress;
use App\Support\CustomerLocation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;
use App\Models\CustomOrderAlternative;
use Illuminate\Validation\ValidationException;

/**
 * Custom ("personal shopper") orders: creation with items and reference
 * images, the shopper marketplace listing, choosing a shopper directly or
 * setting the order up for bids, and the final confirmation with the
 * delivery details that submits the draft to the shopper(s).
 */
class CustomOrderService
{
    public const SHOPPER_SORTS = ['rating', 'distance', 'orders', 'newest'];

    public function __construct(
        protected NotificationService $notifications,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Customer: create / read
    |--------------------------------------------------------------------------
    */

    /**
     * Step 1: create a draft custom order with its items and their reference
     * images.
     *
     * Files are written to disk inside the transaction and removed again if
     * anything fails, so a rolled-back order never leaves orphan uploads.
     * A `shopper_id` records the shopper choice (step 2) right away; the
     * address and delivery time are optional and normally come with confirm.
     *
     * @param  array<string, mixed>  $data  Validated payload of StoreCustomOrderRequest
     * @param  array<int, array<int, UploadedFile>>  $images  Uploaded files keyed by item index
     *
     * @throws CustomOrderException
     */
    public function create(User $customer, array $data, array $images = []): CustomOrder
    {
        $stored = [];

        try {
            $order = DB::transaction(function () use ($customer, $data, $images, &$stored) {
                $shopper = isset($data['shopper_id']) ? $this->availableShopper((int) $data['shopper_id']) : null;
                $budget  = $this->budgetRange($data);
                $address = $this->hasAddressInput($data) ? $this->addressSnapshot($customer, $data) : [];

                $order = CustomOrder::create([
                    'order_number' => CustomOrder::generateNumber(),
                    'user_id'      => $customer->id,
                    'delivery_at'  => $data['delivery_at'] ?? null,
                    'notes'        => $data['notes'] ?? null,
                    'currency'     => (string) config('custom_orders.currency', 'SAR'),
                    'budget_min'   => $budget['min'],
                    'budget_max'   => $budget['max'],
                    'status'       => CustomOrder::STATUS_DRAFT,
                ] + $address);

                foreach (array_values($data['items']) as $index => $item) {
                    $this->createItem($order, $item, $index, $images[$index] ?? [], $stored);
                }

                if ($shopper) {
                    $this->chooseShopper($order, $shopper);
                }

                return $order;
            });
        } catch (Throwable $e) {
            $this->deleteFiles($stored);

            throw $e;
        }

        return $this->load($order);
    }

    /**
     * The customer's custom orders, newest first, optionally by status.
     */
    public function listForCustomer(User $customer, ?string $status, int $perPage): LengthAwarePaginator
    {
        return CustomOrder::query()
            ->forCustomer($customer->id)
            ->when($status, fn (Builder $q) => $q->status($status))
            ->with(['shopper:id,name,phone,avatar', 'shopperProfile'])
            ->withCount('items')
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * One of the customer's custom orders, fully loaded, or 404.
     *
     * @throws ModelNotFoundException
     */
    public function findForCustomer(User $customer, int $id): CustomOrder
    {
        return $this->load(
            CustomOrder::query()->forCustomer($customer->id)->findOrFail($id)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Customer: choose a shopper (step 2)
    |--------------------------------------------------------------------------
    */

    /**
     * Pick a specific shopper.
     *
     * On a draft this only records the choice - the order is submitted to the
     * shopper by confirm(). On a confirmed order that is open for bids the
     * shopper is assigned right away (the pending bids are rejected) and told.
     *
     * @throws CustomOrderException
     */
    public function assignShopper(CustomOrder $order, int $shopperId): CustomOrder
    {
        $order = DB::transaction(function () use ($order, $shopperId) {
            $order   = $this->lock($order);
            $shopper = $this->availableShopper($shopperId);

            if (! $order->isAssignable()) {
                throw CustomOrderException::notAssignable($order->status);
            }

            if ($order->isDraft()) {
                $this->chooseShopper($order, $shopper);

                return $order;
            }

            $order->bids()->pending()->update(['status' => 'rejected', 'responded_at' => now()]);

            $order->forceFill([
                'shopper_id'      => $shopper->id,
                'assignment_mode' => CustomOrder::MODE_DIRECT,
                'assigned_at'     => now(),
            ])->save();

            return $order;
        });

        if ($order->isPending()) {
            $this->notifications->notifyShopperAssigned($order);
        }

        return $this->load($order);
    }

    /**
     * Set the order up for offers from shoppers instead of picking one.
     * The bidding round itself opens (and shoppers are notified) when the
     * draft is confirmed; an order already open for bids is left as is.
     *
     * @throws CustomOrderException
     */
    public function openBidding(CustomOrder $order): CustomOrder
    {
        $order = DB::transaction(function () use ($order) {
            $order = $this->lock($order);

            if (! $order->isAssignable()) {
                throw CustomOrderException::notAssignable($order->status);
            }

            if ($order->isDraft()) {
                $order->forceFill([
                    'shopper_id'      => null,
                    'assignment_mode' => CustomOrder::MODE_BIDDING,
                    'assigned_at'     => null,
                ])->save();
            }

            return $order;
        });

        return $this->load($order);
    }

    /*
    |--------------------------------------------------------------------------
    | Customer: confirm (step 3)
    |--------------------------------------------------------------------------
    */

    /**
     * Confirm a draft with its delivery details and submit it: the address
     * is snapshotted, the delivery time (exact, or a date + slot) is saved,
     * and the order moves draft -> pending. The chosen shopper is notified;
     * in bidding mode the round opens and available shoppers are notified.
     *
     * @param  array<string, mixed>  $data  Validated payload of ConfirmCustomOrderRequest
     *
     * @throws CustomOrderException
     */
    public function confirm(CustomOrder $order, array $data): CustomOrder
    {
        $order = DB::transaction(function () use ($order, $data) {
            $order = $this->lock($order);

            if (! $order->isDraft()) {
                throw CustomOrderException::notConfirmable($order->status);
            }

            if (! $order->hasShopperChoice()) {
                throw CustomOrderException::shopperNotChosen();
            }

            $attributes = $this->addressSnapshot($order->user, $data)
                + $this->deliverySchedule($data)
                + [
                    'confirmation_notes' => $data['confirmation_notes'] ?? null,
                    'submitted_at'       => now(),
                ];

            if ($order->isBidding()) {
                $attributes['bidding_opened_at'] = now();
            } else {
                // The shopper picked in step 2 must still be available
                $this->availableShopper((int) $order->shopper_id);
                $attributes['assigned_at'] = now();
            }

            return $this->transition($order, CustomOrder::STATUS_PENDING, $attributes);
        });

        if ($order->isBidding()) {
            $this->notifyBiddingOpened($order);
        } else {
            $this->notifications->notifyShopperAssigned($order);
        }

        return $this->load($order);
    }

    /**
     * Customer-initiated cancellation of a not-yet-finished order.
     *
     * @throws CustomOrderException
     */
    /**
     * The customer approves / rejects the alternatives the shopper suggested.
     * Once no suggestion is left pending, an order waiting for them resumes
     * the step it paused at: `accepted` (suggested before shopping started)
     * or `in_progress` (suggested while shopping), so the shopper can carry
     * on and enter the purchase details. The shopper is notified either way.
     *
     * @param  array<int, bool>  $decisions  alternative id => approved
     * @return array{order: CustomOrder, approved: int, rejected: int, resumed: bool}
     *
     * @throws CustomOrderException  the order is no longer being shopped (cancelled / completed ...)
     * @throws ValidationException  an alternative was answered meanwhile
     */
    public function respondToAlternatives(CustomOrder $order, array $decisions): array
    {
        $result = DB::transaction(function () use ($order, $decisions) {
            $order = $this->lock($order);

            if (! in_array($order->status, ShopperOrderService::SHOPPING_STATUSES, true)) {
                throw CustomOrderException::alternativesNotOpen($order->status);
            }

            $alternatives = $order->alternatives()
                ->whereKey(array_keys($decisions))
                ->where('status', CustomOrderAlternative::STATUS_PENDING)
                ->lockForUpdate()
                ->get();

            if ($alternatives->count() !== count($decisions)) {
                throw ValidationException::withMessages(['alternatives' => __('custom_orders.alternative_not_pending')]);
            }

            foreach ($alternatives as $alternative) {
                $alternative->forceFill([
                    'status'       => $decisions[$alternative->id] ? CustomOrderAlternative::STATUS_ACCEPTED : CustomOrderAlternative::STATUS_REJECTED,
                    'responded_at' => now(),
                ])->save();
            }

            $resumed = $order->isWaitingForAlternative()
                && $order->alternatives()->where('status', CustomOrderAlternative::STATUS_PENDING)->doesntExist();

            if ($resumed) {
                $order->forceFill(['status' => $order->resumeStatus()])->save();
            }

            $approved = count(array_filter($decisions));

            return ['order' => $order, 'approved' => $approved, 'rejected' => count($decisions) - $approved, 'resumed' => $resumed];
        });

        $this->notifications->notifyShopperAlternativesAnswered($result['order'], $result['approved'], $result['rejected']);

        return ['order' => $this->load($result['order'])] + $result;
    }

    public function cancel(CustomOrder $order, string $actor, ?string $reason = null): CustomOrder
    {
        $order = DB::transaction(function () use ($order, $actor, $reason) {
            $order = $this->lock($order);

            if (! $order->isCancellable()) {
                throw CustomOrderException::notCancellable($order->status);
            }

            $this->transition($order, CustomOrder::STATUS_CANCELLED, [
                'cancelled_at'        => now(),
                'cancelled_by'        => $actor,
                'cancellation_reason' => $reason,
            ]);

            $order->bids()->pending()->update(['status' => 'rejected', 'responded_at' => now()]);

            return $order;
        });

        return $this->load($order);
    }

    /*
    |--------------------------------------------------------------------------
    | Shopper marketplace
    |--------------------------------------------------------------------------
    */

    /**
     * Personal shoppers a customer may choose from, with rating, completed
     * orders, specialties (categories), availability and - with a location -
     * the distance to the customer.
     *
     * @param  array<string, mixed>  $filters  category_id, search, available_only, min_rating, within_km, sort
     */
    public function shoppers(array $filters, ?CustomerLocation $location, int $perPage): LengthAwarePaginator
    {
        $query = ShopperProfile::query()
            ->visible()
            ->with(['user:id,name,avatar', 'categories' => fn ($q) => $q->active()])
            ->withCompletedOrdersCount();

        if (filled($filters['category_id'] ?? null)) {
            $query->inCategory((int) $filters['category_id']);
        }

        if (filled($filters['search'] ?? null)) {
            $query->search((string) $filters['search']);
        }

        if (filter_var($filters['available_only'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->available();
        }

        if (filled($filters['min_rating'] ?? null)) {
            $query->minRating((float) $filters['min_rating']);
        }

        $sort = $filters['sort'] ?? null;

        if ($location) {
            $query->withDistanceTo($location->latitude, $location->longitude);

            if (filled($filters['within_km'] ?? null)) {
                $query->having('distance_km', '<=', (float) $filters['within_km']);
            }

            $sort ??= 'distance';
        } elseif ($sort === 'distance') {
            $sort = 'rating';
        }

        // Available shoppers always float to the top of the list
        $query->orderByDesc('shopper_profiles.is_available');

        match ($sort ?? 'rating') {
            'distance' => $query->orderByRaw('distance_km IS NULL')->orderBy('distance_km')->orderByDesc('shopper_profiles.rating_avg'),
            'orders'   => $query->orderByDesc('completed_orders_count')->orderByDesc('shopper_profiles.rating_avg'),
            'newest'   => $query->orderByDesc('shopper_profiles.id'),
            default    => $query->orderByDesc('shopper_profiles.rating_avg')->orderByDesc('shopper_profiles.rating_count')->orderByDesc('completed_orders_count'),
        };

        return $query->orderBy('shopper_profiles.id')->paginate($perPage);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Move the order to a new status, or throw when the transition is not allowed.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws CustomOrderException
     */
    protected function transition(CustomOrder $order, string $status, array $attributes = []): CustomOrder
    {
        if (! $order->canTransitionTo($status)) {
            throw CustomOrderException::invalidTransition($order->status, $status);
        }

        $order->forceFill($attributes + ['status' => $status])->save();

        return $order;
    }

    /**
     * Record the customer's shopper choice on a draft. The order stays a
     * draft; assignment happens when it is confirmed.
     */
    protected function chooseShopper(CustomOrder $order, User $shopper): void
    {
        $order->forceFill([
            'shopper_id'        => $shopper->id,
            'assignment_mode'   => CustomOrder::MODE_DIRECT,
            'bidding_opened_at' => null,
            'assigned_at'       => null,
        ])->save();
    }

    /**
     * Tell every available shopper the order is open for their offers.
     */
    protected function notifyBiddingOpened(CustomOrder $order): void
    {
        $shoppers = User::query()
            ->active()
            ->ofType(User::TYPE_SHOPPER)
            ->whereHas('shopperProfile', fn (Builder $q) => $q->where('status', 'approved')->where('is_available', true))
            ->get();

        if ($shoppers->isNotEmpty()) {
            $this->notifications->notifyShoppersBiddingOpened($order, $shoppers);
        }
    }

    /**
     * A shopper account the customer may choose right now: active account,
     * approved profile, marked available.
     *
     * @throws CustomOrderException
     */
    protected function availableShopper(int $shopperId): User
    {
        $shopper = User::query()
            ->whereKey($shopperId)
            ->active()
            ->ofType(User::TYPE_SHOPPER)
            ->whereHas('shopperProfile', fn (Builder $q) => $q->where('status', 'approved')->where('is_available', true))
            ->first();

        return $shopper ?? throw CustomOrderException::shopperUnavailable();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasAddressInput(array $data): bool
    {
        return isset($data['address_id']) || is_array($data['address'] ?? null);
    }

    /**
     * Snapshot of where to deliver: a saved address of the customer
     * (`address_id`) or an inline `address` block.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws CustomOrderException
     */
    protected function addressSnapshot(User $customer, array $data): array
    {
        if (isset($data['address_id'])) {
            /** @var UserAddress|null $address */
            $address = $customer->addresses()->find((int) $data['address_id']);

            if (! $address) {
                throw CustomOrderException::addressNotFound();
            }

            return CustomOrder::deliveryAddressAttributes($address, $customer);
        }

        $address = $data['address'] ?? null;

        if (! is_array($address) || blank($address['city'] ?? null)) {
            throw CustomOrderException::addressRequired();
        }

        $line = implode(', ', array_filter([
            $address['building_number'] ?? null,
            $address['street'] ?? null,
            $address['district'] ?? null,
            $address['city'],
        ]));

        return [
            'delivery_address_id'      => null,
            'delivery_name'            => $customer->name,
            'delivery_phone'           => $address['phone'] ?? $customer->phone,
            'delivery_location_name'   => $address['location_name'] ?? null,
            'delivery_city'            => $address['city'],
            'delivery_district'        => $address['district'] ?? null,
            'delivery_street'          => $address['street'] ?? null,
            'delivery_building_number' => $address['building_number'] ?? null,
            'delivery_address'         => $line,
            'delivery_latitude'        => $address['latitude'] ?? null,
            'delivery_longitude'       => $address['longitude'] ?? null,
        ];
    }

    /**
     * Snapshot of when to deliver: an exact `delivery_at`, or a
     * `delivery_date` + `delivery_slot_id` whose window is stored like the
     * store orders do. The slot must start inside the configured lead window.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws CustomOrderException
     */
    protected function deliverySchedule(array $data): array
    {
        if (! isset($data['delivery_slot_id'])) {
            $at = Carbon::createFromFormat('Y-m-d H:i', $data['delivery_at'])->startOfMinute();

            return [
                'delivery_at'           => $at,
                'delivery_date'         => $at->toDateString(),
                'delivery_slot_id'      => null,
                'delivery_slot_label'   => null,
                'delivery_window_start' => null,
                'delivery_window_end'   => null,
            ];
        }

        $timezone = (string) config('checkout.delivery.timezone', config('app.timezone'));
        $delivery = config('custom_orders.delivery');

        $slot = DeliverySlot::query()->active()->find((int) $data['delivery_slot_id']);
        $day  = Carbon::createFromFormat('Y-m-d', (string) $data['delivery_date'], $timezone)->startOfDay();

        if (! $slot) {
            throw CustomOrderException::slotUnavailable();
        }

        $start = $slot->startsAt($day);
        $now   = Carbon::now($timezone);

        if ($start->lt($now->copy()->addMinutes((int) $delivery['min_lead_minutes']))
            || $start->gt($now->copy()->addDays((int) $delivery['max_lead_days']))) {
            throw CustomOrderException::slotUnavailable();
        }

        return [
            'delivery_at'           => $start,
            'delivery_date'         => $day->toDateString(),
            'delivery_slot_id'      => $slot->id,
            'delivery_slot_label'   => $slot->timeRange(),
            'delivery_window_start' => $start,
            'delivery_window_end'   => $slot->endsAt($day),
        ];
    }

    /**
     * Explicit budget range, or the sum of the items' expected ranges
     * (a bound stays null when no item declares it).
     *
     * @param  array<string, mixed>  $data
     * @return array{min: float|null, max: float|null}
     */
    protected function budgetRange(array $data): array
    {
        if (isset($data['budget_min']) || isset($data['budget_max'])) {
            return [
                'min' => isset($data['budget_min']) ? round((float) $data['budget_min'], 2) : null,
                'max' => isset($data['budget_max']) ? round((float) $data['budget_max'], 2) : null,
            ];
        }

        $min = null;
        $max = null;

        foreach ($data['items'] as $item) {
            $qty = (int) ($item['quantity'] ?? 1);

            if (isset($item['expected_price_min'])) {
                $min = ($min ?? 0) + (float) $item['expected_price_min'] * $qty;
            }
            if (isset($item['expected_price_max'])) {
                $max = ($max ?? 0) + (float) $item['expected_price_max'] * $qty;
            }
        }

        return [
            'min' => $min !== null ? round($min, 2) : null,
            'max' => $max !== null ? round($max, 2) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, UploadedFile>  $images
     * @param  array<int, string>  $stored  Paths written so far (for cleanup on failure)
     */
    protected function createItem(CustomOrder $order, array $item, int $index, array $images, array &$stored): CustomOrderItem
    {
        $line = $order->items()->create([
            'product_name'       => $item['product_name'],
            'description'        => $item['description'] ?? null,
            'quantity'           => (int) ($item['quantity'] ?? 1),
            'expected_price_min' => $item['expected_price_min'] ?? null,
            'expected_price_max' => $item['expected_price_max'] ?? null,
            'sort_order'         => $index,
        ]);

        $directory = trim((string) config('custom_orders.items.media_directory', 'custom-orders'), '/')."/{$order->id}";

        foreach (array_values($images) as $position => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store($directory, 'public');

            if ($path === false) {
                throw new \RuntimeException('Unable to store the uploaded reference image.');
            }

            $stored[] = $path;

            $line->media()->create([
                'disk'          => 'public',
                'path'          => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type'     => $file->getClientMimeType(),
                'size'          => $file->getSize(),
                'sort_order'    => $position,
            ]);
        }

        return $line;
    }

    /**
     * @param  array<int, string>  $paths
     */
    protected function deleteFiles(array $paths): void
    {
        foreach ($paths as $path) {
            Storage::disk('public')->delete($path);
        }
    }

    protected function lock(CustomOrder $order): CustomOrder
    {
        return CustomOrder::query()->lockForUpdate()->findOrFail($order->getKey());
    }

    /**
     * Everything the details screen renders.
     */
    protected function load(CustomOrder $order): CustomOrder
    {
        return $order->load([
            'items.media',
            'items.alternatives',
            'pickupAddress',   // where the shopper hands the items over (set with the invoice)
            'shopper:id,name,phone,avatar',
            'shopperProfile',
            'bids.shopper:id,name,avatar',
            'bids.shopperProfile',
        ])->loadCount('items');
    }
}
