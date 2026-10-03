<?php

namespace App\Services;

use App\Exceptions\CheckoutException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cart operations for a customer. A cart may hold products of several stores
 * (merchant accounts, `products.store_id`); checkout splits it into one order
 * per store.
 */
class CartService
{
    /**
     * The customer's cart, created on first use. Loads what the cart screen needs.
     */
    public function forUser(User $user, bool $withRelations = true): Cart
    {
        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);

        if ($withRelations) {
            $cart->load([
                'items.product.storeProfile',
                'items.addons',
                'address',
                'deliverySlot',
                'promoCode',
            ]);
        }

        return $cart;
    }

    /**
     * Add a product (or increase its quantity when already present).
     *
     * @param  array<int, int>  $addonIds
     */
    public function addItem(User $user, int $productId, int $quantity, array $addonIds = []): Cart
    {
        return DB::transaction(function () use ($user, $productId, $quantity, $addonIds) {
            $cart = Cart::query()->lockForUpdate()->firstOrCreate(['user_id' => $user->id]);

            $product = Product::query()->visible()->notExpired()->find($productId)
                ?? throw CheckoutException::productUnavailable('#'.$productId);

            /** @var CartItem|null $item */
            $item = $cart->items()->where('product_id', $product->id)->first();
            $newQuantity = ($item?->quantity ?? 0) + $quantity;

            $this->assertQuantity($product, $newQuantity);

            if ($item) {
                $item->update(['quantity' => $newQuantity]);
            } else {
                $item = $cart->items()->create(['product_id' => $product->id, 'quantity' => $newQuantity]);
            }

            $item->addons()->sync($this->validAddonIds($product, $addonIds));

            return $this->forUser($user);
        });
    }

    /**
     * Change the quantity / add-ons of a line.
     *
     * @param  array<int, int>|null  $addonIds  Null keeps the current add-ons.
     */
    public function updateItem(User $user, int $itemId, ?int $quantity, ?array $addonIds): Cart
    {
        return DB::transaction(function () use ($user, $itemId, $quantity, $addonIds) {
            $cart = Cart::query()->lockForUpdate()->firstOrCreate(['user_id' => $user->id]);
            $item = $this->findItem($cart, $itemId);
            $product = $item->product;

            if ($quantity !== null) {
                $this->assertQuantity($product, $quantity);
                $item->update(['quantity' => $quantity]);
            }

            if ($addonIds !== null) {
                $item->addons()->sync($this->validAddonIds($product, $addonIds));
            }

            return $this->forUser($user);
        });
    }

    public function removeItem(User $user, int $itemId): Cart
    {
        return DB::transaction(function () use ($user, $itemId) {
            $cart = Cart::query()->lockForUpdate()->firstOrCreate(['user_id' => $user->id]);

            $this->findItem($cart, $itemId)->delete();

            if ($cart->items()->doesntExist()) {
                $cart->resetAfterEmptied();
            }

            return $this->forUser($user);
        });
    }

    /**
     * Empty the cart and forget the checkout selections (address is kept).
     */
    public function clear(User $user): Cart
    {
        return DB::transaction(function () use ($user) {
            $cart = Cart::query()->lockForUpdate()->firstOrCreate(['user_id' => $user->id]);

            $cart->items()->delete();
            $cart->resetAfterEmptied();

            return $this->forUser($user);
        });
    }

    /**
     * Ensure every line can still be ordered: each store visible, product on
     * sale, stock sufficient. Called right before placing the order.
     */
    public function assertOrderable(Cart $cart): void
    {
        $cart->loadMissing(['items.product.store.storeProfile', 'items.addons']);

        if ($cart->items->isEmpty()) {
            throw CheckoutException::cartEmpty();
        }

        foreach ($cart->items as $item) {
            $product = $item->product;

            if (! $product || $product->isExpired()) {
                throw CheckoutException::productUnavailable($product?->name ?? '#'.$item->product_id);
            }

            $store = $product->store;
            if (! $store || ! $store->isActive() || ! $store->storeProfile?->isApproved()) {
                throw CheckoutException::storeUnavailable();
            }

            $this->assertQuantity($product, $item->quantity);

            foreach ($item->addons as $addon) {
                if (! $addon->is_active || $addon->store_id !== $product->store_id) {
                    throw CheckoutException::productUnavailable($addon->name);
                }
                if ($addon->stock_quantity < $item->quantity) {
                    throw CheckoutException::insufficientStock($addon->name, $addon->stock_quantity);
                }
            }
        }
    }

    protected function assertQuantity(Product $product, int $quantity): void
    {
        $max = (int) config('checkout.cart.max_quantity_per_item', 50);

        if ($quantity < 1 || $quantity > $max) {
            throw ValidationException::withMessages([
                'quantity' => [__('validation.between.numeric', ['attribute' => __('validation.attributes.quantity'), 'min' => 1, 'max' => $max])],
            ]);
        }

        if ($product->stock_quantity < $quantity) {
            throw CheckoutException::insufficientStock($product->name, $product->stock_quantity);
        }
    }

    /**
     * Keep only add-ons the product's store actually offers for its category.
     *
     * @param  array<int, int>  $addonIds
     * @return array<int, int>
     */
    protected function validAddonIds(Product $product, array $addonIds): array
    {
        if ($addonIds === []) {
            return [];
        }

        $valid = $product->availableAddons()->whereIn('addons.id', $addonIds)->pluck('addons.id')->all();
        $invalid = array_values(array_diff($addonIds, $valid));

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'addon_ids' => [__('checkout.invalid_addons', ['ids' => implode(', ', $invalid)])],
            ]);
        }

        return $valid;
    }

    protected function findItem(Cart $cart, int $itemId): CartItem
    {
        return $cart->items()->with(['product', 'addons'])->find($itemId)
            ?? throw CheckoutException::itemNotFound();
    }
}
