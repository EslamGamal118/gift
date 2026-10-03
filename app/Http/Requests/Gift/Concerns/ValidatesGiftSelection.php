<?php

namespace App\Http\Requests\Gift\Concerns;

use App\Models\Product;
use App\Services\GiftService;
use Closure;

/**
 * What the customer picks on the online gift screen, validated the same way
 * for the details / summary screen and for the checkout:
 * product_id (special category), quantity, addon_ids (greeting cards, ...) and promo_code.
 */
trait ValidatesGiftSelection
{
    protected ?Product $giftProduct = null;

    protected bool $giftProductResolved = false;

    /**
     * Accept `addon_ids[]=1&addon_ids[]=2` and `addon_ids=1,2`.
     */
    protected function prepareGiftSelection(): void
    {
        $addons = $this->input('addon_ids');

        $this->merge(array_filter([
            'addon_ids'  => is_string($addons) ? array_values(array_filter(explode(',', $addons), 'strlen')) : null,
            'promo_code' => $this->filled('promo_code') ? trim((string) $this->input('promo_code')) : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function giftSelectionRules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'bail', function (string $attribute, mixed $value, Closure $fail) {
                if (! $this->giftProduct()) {
                    $fail(__('gifts.not_giftable'));
                }
            }],
            'quantity'   => ['nullable', 'integer', 'min:1', 'max:'.(int) config('gifts.max_quantity', 10)],
            'addon_ids'  => ['nullable', 'array', 'max:10'],
            'addon_ids.*' => ['integer', 'distinct', function (string $attribute, mixed $value, Closure $fail) {
                $product = $this->giftProduct();

                if ($product && ! $product->availableAddons()->whereKey((int) $value)->exists()) {
                    $fail(__('gifts.addon_unavailable'));
                }
            }],
            'promo_code' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * The requested product when it is a giftable one (special category, visible, current).
     */
    public function giftProduct(): ?Product
    {
        if (! $this->giftProductResolved) {
            $id = filter_var($this->input('product_id'), FILTER_VALIDATE_INT);

            $this->giftProduct         = $id ? app(GiftService::class)->giftableQuery()->find($id) : null;
            $this->giftProductResolved = true;
        }

        return $this->giftProduct;
    }

    public function quantity(): int
    {
        return (int) ($this->validated('quantity') ?? 1);
    }

    /**
     * @return array<int, int>
     */
    public function addonIds(): array
    {
        return array_map('intval', $this->validated('addon_ids') ?? []);
    }

    public function promoCode(): ?string
    {
        return $this->validated('promo_code');
    }

    /**
     * @return array<string, string>
     */
    protected function giftSelectionAttributes(): array
    {
        return [
            'product_id' => __('validation.attributes.product_id'),
            'quantity'   => __('validation.attributes.quantity'),
            'addon_ids'  => __('validation.attributes.addon_ids'),
            'addon_ids.*' => __('validation.attributes.addon_ids'),
            'promo_code' => __('validation.attributes.promo_code'),
        ];
    }
}
