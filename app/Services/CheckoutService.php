<?php

namespace App\Services;

use App\Exceptions\CheckoutException;
use App\Models\Addon;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CheckoutGroup;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\StoreProfile;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Checkout review (financial breakdown) and order placement.
 *
 * The cart carries the customer's selections and may span several stores;
 * this service validates them as a whole, prices each store's share and
 * freezes everything into a CheckoutGroup the customer pays once. The
 * per-store orders are created from it after the payment is confirmed.
 */
class CheckoutService
{
    public function __construct(
        protected CartService $carts,
        protected DeliverySchedulingService $scheduling,
        protected PromoCodeService $promos,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Selections stored on the cart
    |--------------------------------------------------------------------------
    */

    public function selectAddress(Cart $cart, UserAddress $address): Cart
    {
        $cart->forceFill(['address_id' => $address->id])->save();

        return $cart;
    }

    public function setGiftMessage(Cart $cart, ?string $message): Cart
    {
        $cart->forceFill(['gift_message' => $message !== null && trim($message) !== '' ? trim($message) : null])->save();

        return $cart;
    }

    /**
     * @throws CheckoutException when the code cannot be used on this cart
     */
    public function applyPromo(Cart $cart, User $user, string $code): PromoCode
    {
        $promo = $this->promos->resolveForCart($code, $user, $this->storeSubtotals($cart->itemsByStore()));

        $cart->forceFill(['promo_code_id' => $promo->id])->save();
        $cart->setRelation('promoCode', $promo);

        return $promo;
    }

    public function removePromo(Cart $cart): Cart
    {
        $cart->forceFill(['promo_code_id' => null])->save();
        $cart->unsetRelation('promoCode');

        return $cart;
    }

    /*
    |--------------------------------------------------------------------------
    | Summary (checkout review screen)
    |--------------------------------------------------------------------------
    */

    /**
     * Everything the review screen shows, plus `ready` / `missing` so the client
     * knows whether "place order" can be pressed. `stores` is the per-store
     * split the order will be placed with; `totals` is what is paid, once.
     *
     * @return array<string, mixed>
     */
    public function summary(Cart $cart, User $user): array
    {
        $cart->loadMissing(['items.product.storeProfile', 'items.addons', 'address', 'deliverySlot', 'promoCode']);

        $this->applyDefaultAddress($cart, $user);
        $byStore = $cart->itemsByStore();

        // A promo that stopped being valid is dropped silently but reported.
        $promo = $cart->promoCode;
        $promoError = null;
        if ($promo) {
            try {
                $this->promos->assertUsableForCart($promo, $user, $this->storeSubtotals($byStore));
            } catch (CheckoutException $e) {
                $promoError = __($e->getMessageKey(), $e->getReplace());
                $this->removePromo($cart);
                $promo = null;
            }
        }

        $stores = $this->breakdown($cart, $byStore, $cart->address, $promo);
        $totals = $this->sumTotals($stores);
        $quote = $this->scheduling->quoteForCart($cart, $cart->address, $cart->delivery_type);

        $missing = [];
        if ($cart->items->isEmpty()) {
            $missing[] = 'items';
        }
        if (! $cart->address) {
            $missing[] = 'address';
        }
        if (! $cart->hasDeliverySelection()) {
            $missing[] = 'delivery';
        }

        return [
            'cart' => $cart,
            'stores' => $stores,
            'address' => $cart->address,
            'delivery' => $this->deliveryView($cart, $quote),
            'gift_message' => $cart->gift_message,
            'promo' => $promo ? [
                'code' => $promo->code,
                'type' => $promo->type,
                'value' => (float) $promo->value,
                'discount' => $totals['discount'],
            ] : null,
            'promo_error' => $promoError,
            'totals' => $totals,
            'payment_methods' => config('checkout.orders.payment_methods', []),
            'ready' => $missing === [],
            'missing' => $missing,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Place order
    |--------------------------------------------------------------------------
    */

    /**
     * Open a checkout for the cart, to be paid once. Nothing is reserved or
     * created yet: the cart stays as it is, and the per-store orders are
     * created from the checkout's snapshot only when the payment is confirmed
     * (see createOrders()).
     *
     * @throws CheckoutException
     */
    public function startCheckout(User $user, string $paymentMethod): CheckoutGroup
    {
        return DB::transaction(function () use ($user, $paymentMethod) {
            /** @var Cart $cart */
            $cart = Cart::query()->lockForUpdate()->firstOrCreate(['user_id' => $user->id]);
            $cart->load(['items.product.storeProfile', 'items.addons', 'address', 'deliverySlot', 'promoCode']);

            // 1) Everything selected and still valid (stock included)
            $this->carts->assertOrderable($cart);

            $address = $this->applyDefaultAddress($cart, $user) ?? throw CheckoutException::addressRequired();
            $this->scheduling->assertSelectionStillValid($cart);

            $byStore = $cart->itemsByStore();
            $promo = $cart->promoCode;
            if ($promo) {
                $this->promos->assertUsableForCart($promo, $user, $this->storeSubtotals($byStore));
            }

            // 2) Pricing, per store and in total
            $stores = $this->breakdown($cart, $byStore, $address, $promo);
            $totals = $this->sumTotals($stores);

            // 3) The single payment, carrying everything the orders will be made of
            return CheckoutGroup::query()->create([
                'reference' => CheckoutGroup::generateReference(),
                'user_id' => $user->id,
                'status' => CheckoutGroup::STATUS_PENDING_PAYMENT,
                'currency' => $totals['currency'],
                'subtotal' => $totals['subtotal'],
                'delivery_fee' => $totals['delivery_fee'],
                'express_fee' => $totals['express_fee'],
                'discount_amount' => $totals['discount'],
                'tax_amount' => $totals['tax'],
                'total_amount' => $totals['total'],
                'payment_method' => $paymentMethod,
                'payment_status' => Order::PAYMENT_PENDING,
                'snapshot' => $this->snapshot($cart, $user, $address, $stores, $promo),
            ]);
        });
    }

    /**
     * Create a paid checkout's store orders from its snapshot, take the stock,
     * count the promo use and remove the bought lines from the cart. Runs
     * inside PaymentService's transaction with the group row locked, once.
     *
     * The money is already taken, so the orders are always created: a product
     * that sold out meanwhile has its stock floored at 0 and the order is
     * flagged (`payment_data.stock_shortfall`) for the store / admin.
     *
     * @return list<Order> Created in `pending_payment`; the caller marks them paid
     */
    public function createOrders(CheckoutGroup $group): array
    {
        $snapshot = $group->snapshot;

        // Placed before orders were deferred: they exist already
        if (! $snapshot || $group->orders()->exists()) {
            return [];
        }

        $orders = [];

        foreach ($snapshot['stores'] as $store) {
            /** @var Order $order */
            $order = Order::query()->create([
                'checkout_group_id' => $group->id,
                'order_number' => Order::generateNumber(),
                'store_id' => $store['store_id'],
                'promo_code_id' => $store['promo_applies'] ? $snapshot['promo_code_id'] : null,
                'promo_code' => $store['promo_applies'] ? $snapshot['promo_code'] : null,
                'currency' => $store['totals']['currency'],
                'subtotal' => $store['totals']['subtotal'],
                'delivery_fee' => $store['totals']['delivery_fee'],
                'express_fee' => $store['totals']['express_fee'],
                'discount_amount' => $store['totals']['discount'],
                'tax_rate' => $store['totals']['tax_rate'],
                'tax_amount' => $store['totals']['tax'],
                'total_amount' => $store['totals']['total'],
                'payment_method' => $group->payment_method,
                'payment_status' => Order::PAYMENT_PENDING,
                'status' => Order::STATUS_PENDING_PAYMENT,
            ] + $snapshot['order']);

            $shortfall = [];
            foreach ($store['items'] as $line) {
                $shortfall = [...$shortfall, ...$this->createOrderItem($order, $line)];
            }

            if ($shortfall !== []) {
                Log::warning('Paid checkout oversold', ['checkout' => $group->reference, 'order' => $order->order_number, 'shortfall' => $shortfall]);
                $order->mergePaymentData(['stock_shortfall' => $shortfall])->save();
            }

            $orders[] = $order;
        }

        // One checkout = one use of the code, however many stores it covers
        if ($snapshot['promo_code_id']) {
            PromoCode::query()->whereKey($snapshot['promo_code_id'])->increment('used_count');
        }

        $this->removeBoughtLines((int) $group->user_id, $snapshot['cart_item_ids']);

        return $orders;
    }

    /**
     * Give reserved stock back (order cancelled before fulfilment).
     */
    public function releaseStock(Order $order): void
    {
        $order->loadMissing('items.addons');

        foreach ($order->items as $item) {
            if ($item->product_id) {
                Product::query()->whereKey($item->product_id)->increment('stock_quantity', $item->quantity);
            }
            foreach ($item->addons as $addon) {
                if ($addon->addon_id) {
                    Addon::query()->whereKey($addon->addon_id)->increment('stock_quantity', $addon->quantity);
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Financial breakdown. Tax applies to (subtotal - discount + delivery charges).
     *
     * @return array{
     *     currency: string, subtotal: float, delivery_fee: float, express_fee: float,
     *     discount: float, tax_rate: float, tax: float, total: float, prices_include_tax: bool
     * }
     */
    public function totals(float $subtotal, float $discount, float $deliveryFee, float $expressFee): array
    {
        $rate = (float) config('checkout.tax.rate', 0);
        $inclusive = (bool) config('checkout.tax.prices_include_tax', false);
        $discount = round(min($discount, $subtotal), 2);
        $taxableBase = round($subtotal - $discount + $deliveryFee + $expressFee, 2);

        if ($inclusive) {
            $tax = round($taxableBase - ($taxableBase / (1 + $rate)), 2);
            $total = $taxableBase;
        } else {
            $tax = round($taxableBase * $rate, 2);
            $total = round($taxableBase + $tax, 2);
        }

        return [
            'currency' => (string) config('checkout.currency', 'SAR'),
            'subtotal' => round($subtotal, 2),
            'delivery_fee' => round($deliveryFee, 2),
            'express_fee' => round($expressFee, 2),
            'discount' => $discount,
            'tax_rate' => $rate,
            'tax' => $tax,
            'total' => max(0, $total),
            'prices_include_tax' => $inclusive,
        ];
    }

    /**
     * Each store's share of the cart, priced as the order it will become: its
     * lines, its own delivery quote and its share of the promo discount.
     * Keyed by merchant account id.
     *
     * @param  Collection<int, Collection<int, CartItem>>  $byStore  Cart::itemsByStore()
     * @return array<int, array{store_id: int, store: ?StoreProfile, items: Collection<int, CartItem>, delivery: array<string, mixed>, totals: array<string, mixed>}>
     */
    protected function breakdown(Cart $cart, Collection $byStore, ?UserAddress $address, ?PromoCode $promo): array
    {
        $discounts = $this->promos->allocate($promo, $this->storeSubtotals($byStore));

        return $byStore->map(function (Collection $items, int $storeId) use ($cart, $address, $discounts) {
            $store = $items->first()->product->storeProfile;
            $quote = $this->scheduling->quote($store, $address, $cart->delivery_type);

            return [
                'store_id' => $storeId,
                'store' => $store,
                'items' => $items->values(),
                'delivery' => $quote,
                'totals' => $this->totals(Cart::linesTotal($items), $discounts[$storeId] ?? 0.0, $quote['delivery_fee'], $quote['express_fee']),
            ];
        })->all();
    }

    /**
     * Grand totals of a breakdown: the sum of what each store's order will
     * charge, so the single payment always equals the orders' totals.
     *
     * @param  array<int, array{totals: array<string, mixed>}>  $stores
     * @return array<string, mixed>
     */
    protected function sumTotals(array $stores): array
    {
        $sum = $this->totals(0, 0, 0, 0);

        foreach ($stores as $share) {
            foreach (['subtotal', 'delivery_fee', 'express_fee', 'discount', 'tax', 'total'] as $key) {
                $sum[$key] = round($sum[$key] + $share['totals'][$key], 2);
            }
        }

        return $sum;
    }

    /**
     * @param  Collection<int, Collection<int, CartItem>>  $byStore
     * @return array<int, float> merchant account id => subtotal
     */
    protected function storeSubtotals(Collection $byStore): array
    {
        return $byStore->map(fn (Collection $items) => Cart::linesTotal($items))->all();
    }

    /**
     * The cart's address, falling back to (and remembering) the customer's
     * default address so a returning customer need not re-select it.
     */
    protected function applyDefaultAddress(Cart $cart, User $user): ?UserAddress
    {
        if ($cart->address) {
            return $cart->address;
        }

        $default = $user->addresses()->default()->first() ?? $user->addresses()->latest('id')->first();

        if ($default) {
            $this->selectAddress($cart, $default);
            $cart->setRelation('address', $default);
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $quote  DeliverySchedulingService::quoteForCart()
     * @return array<string, mixed>
     */
    protected function deliveryView(Cart $cart, array $quote): array
    {
        $slot = $cart->deliverySlot;

        return [
            'type' => $cart->delivery_type,
            'is_instant' => $cart->isInstantDelivery(),
            'date' => $cart->delivery_date?->toDateString(),
            'slot' => $slot ? [
                'id' => $slot->id,
                'label' => $slot->label,
                'period' => $slot->period,
                'time_range' => $slot->timeRange(),
            ] : null,
            'eta_minutes' => $quote['eta_minutes'],
            'fee' => $quote['delivery_fee'],          // summed over the stores
            'express_fee' => $quote['express_fee'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function addressSnapshot(User $user, UserAddress $address): array
    {
        return [
            'address_id' => $address->id,
            'shipping_name' => $user->name,
            'shipping_phone' => $address->phone ?: $user->phone,
            'shipping_email' => $user->email,
            'shipping_location_name' => $address->location_name,
            'shipping_city' => $address->city,
            'shipping_district' => $address->district,
            'shipping_street' => $address->street,
            'shipping_building_number' => $address->building_number,
            'shipping_address' => $address->toLine(),
            'shipping_latitude' => $address->latitude,
            'shipping_longitude' => $address->longitude,
        ];
    }

    /**
     * Everything the orders will be made of, frozen when the payment starts:
     * the customer pays these prices whatever happens to the cart meanwhile.
     *
     * @param  array<int, array<string, mixed>>  $stores  breakdown()
     * @return array<string, mixed>
     */
    protected function snapshot(Cart $cart, User $user, UserAddress $address, array $stores, ?PromoCode $promo): array
    {
        $order = [
            'user_id' => $user->id,
            'gift_message' => $cart->gift_message,
        ] + $this->addressSnapshot($user, $address) + $this->scheduling->orderSnapshot($cart);

        return [
            'order' => array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value, $order),
            'promo_code_id' => $promo?->id,
            'promo_code' => $promo?->code,
            'cart_item_ids' => $cart->items->pluck('id')->all(),
            'stores' => collect($stores)->map(fn (array $share, int $storeId) => [
                'store_id' => $storeId,
                'promo_applies' => (bool) $promo?->appliesToStore($storeId),
                'totals' => $share['totals'],
                'items' => $share['items']->map(fn (CartItem $item) => $this->lineSnapshot($item))->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function lineSnapshot(CartItem $item): array
    {
        $product = $item->product;
        $addons = $item->addons->map(fn (Addon $addon) => [
            'addon_id' => $addon->id,
            'name' => $addon->name,
            'unit_price' => (float) $addon->price,
            'quantity' => $item->quantity,
            'subtotal' => round((float) $addon->price * $item->quantity, 2),
        ])->values()->all();
        $addonsTotal = round(array_sum(array_column($addons, 'subtotal')), 2);

        return [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_image' => $product->image,
            'unit_price' => (float) $product->price,
            'quantity' => $item->quantity,
            'addons' => $addons,
            'addons_total' => $addonsTotal,
            'subtotal' => round(((float) $product->price * $item->quantity) + $addonsTotal, 2),
        ];
    }

    /**
     * Create one order line from its snapshot and take the stock (product and
     * add-ons) under row locks, never below 0.
     *
     * @param  array<string, mixed>  $line  lineSnapshot()
     * @return list<array{name: string, ordered: int, available: int}> What was short
     */
    protected function createOrderItem(Order $order, array $line): array
    {
        $quantity = (int) $line['quantity'];
        $stock = [[Product::class, $line['product_id'], $line['product_name']]];
        foreach ($line['addons'] as $addon) {
            $stock[] = [Addon::class, $addon['addon_id'], $addon['name']];
        }

        $shortfall = [];
        foreach ($stock as [$model, $id, $name]) {
            $row = $model::query()->lockForUpdate()->find($id);
            $available = (int) ($row?->stock_quantity ?? 0);

            if ($available < $quantity) {
                $shortfall[] = ['name' => $name, 'ordered' => $quantity, 'available' => $available];
            }

            $row?->decrement('stock_quantity', min($available, $quantity));
        }

        /** @var OrderItem $item */
        $item = $order->items()->create([
            'product_id' => $line['product_id'],
            'product_name' => $line['product_name'],
            'product_image' => $line['product_image'],
            'unit_price' => $line['unit_price'],
            'quantity' => $quantity,
            'addons_total' => $line['addons_total'],
            'subtotal' => $line['subtotal'],
        ]);

        if ($line['addons'] !== []) {
            $item->addons()->createMany($line['addons']);
        }

        return $shortfall;
    }

    /**
     * Drop the paid-for lines from the customer's cart. Anything added after
     * the payment started stays for next time.
     *
     * @param  list<int>  $cartItemIds
     */
    protected function removeBoughtLines(int $userId, array $cartItemIds): void
    {
        $cart = Cart::query()->lockForUpdate()->where('user_id', $userId)->first();

        if (! $cart) {
            return;
        }

        $cart->items()->whereKey($cartItemIds)->delete();

        if (! $cart->items()->exists()) {
            $cart->resetAfterEmptied();
        }
    }
}
