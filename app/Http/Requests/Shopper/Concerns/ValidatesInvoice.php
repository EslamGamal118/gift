<?php

namespace App\Http\Requests\Shopper\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * The purchase invoice form (إضافة الأسعار والفاتورة), multipart:
 * { invoice_image, pickup_address_id | pickup_address{...}, shopper_fees, items: [{ id, unit_price }] }
 *
 * Where the driver picks the items up (عنوان الاستلام), one of:
 *   pickup_address_id   one of the shopper's own saved addresses
 *   pickup_address[location_name|city|district|street|building_number|latitude?|longitude?]
 *                       a new address, saved for the shopper
 * The customer's delivery address is not part of this form.
 *
 * `unit_price` is what the shopper paid per unit; each `id` must be an item
 * of this order (once). The final amount is not sent: the server computes it
 * from the item prices and the shopper's fees (see ShopperOrderService). `items[].item_id` / `items[].actual_price` are accepted
 * for `id` / `unit_price`.
 *
 * Used by a ShopperOrderActionRequest (provides order()).
 */
trait ValidatesInvoice
{
    /**
     * Columns of a pickup address created from the form.
     *
     * @var list<string>
     */
    public const PICKUP_ADDRESS_FIELDS = ['location_name', 'city', 'district', 'street', 'building_number', 'latitude', 'longitude'];

    /**
     * Trim the pickup address, map the alternative item keys; call from prepareForValidation().
     */
    protected function normalizeInvoiceInput(): void
    {
        if ($this->input('pickup_address_id') === '') {
            $this->merge(['pickup_address_id' => null]);
        }

        if (is_array($address = $this->input('pickup_address'))) {
            foreach (self::PICKUP_ADDRESS_FIELDS as $field) {
                if (is_string($address[$field] ?? null)) {
                    $address[$field] = trim($address[$field]) ?: null;
                }
            }

            $this->merge(['pickup_address' => $address]);
        }

        if (is_array($items = $this->input('items'))) {
            $this->merge(['items' => array_map(fn ($item) => is_array($item) ? $item + array_filter([
                'id'         => $item['item_id'] ?? null,
                'unit_price' => $item['actual_price'] ?? null,
            ], fn ($value) => $value !== null) : $item, $items)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function invoiceRules(): array
    {
        $limits   = config('custom_orders.items');
        $maxPrice = 'max:'.$limits['max_price'];

        return [
            'invoice_image'      => ['required', 'file', 'image', 'mimes:'.implode(',', $limits['invoice_mimes']), 'max:'.$limits['max_invoice_kb']],
            'pickup_address_id'  => [
                'nullable', 'integer', 'required_without:pickup_address', 'prohibits:pickup_address',
                Rule::exists('user_addresses', 'id')->where('user_id', $this->user()->id),
            ],
            'pickup_address'                 => ['nullable', 'array', 'required_without:pickup_address_id'],
            'pickup_address.location_name'   => ['required_with:pickup_address', 'string', 'max:100'],
            'pickup_address.city'            => ['required_with:pickup_address', 'string', 'min:2', 'max:100'],
            'pickup_address.district'        => ['required_with:pickup_address', 'string', 'max:100'],
            'pickup_address.street'          => ['required_with:pickup_address', 'string', 'max:150'],
            'pickup_address.building_number' => ['required_with:pickup_address', 'string', 'max:30'],
            'pickup_address.latitude'        => ['nullable', 'numeric', 'between:-90,90', 'required_with:pickup_address.longitude'],
            'pickup_address.longitude'       => ['nullable', 'numeric', 'between:-180,180', 'required_with:pickup_address.latitude'],
            'shopper_fees'       => ['required', 'numeric', 'min:0', $maxPrice],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.id'         => [
                'required', 'integer', 'distinct',
                Rule::exists('custom_order_items', 'id')->where('custom_order_id', $this->order()->id),
            ],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', $maxPrice],
        ];
    }

    /**
     * @return array{image: UploadedFile, pickup_address_id: ?int, pickup_address: ?array<string, mixed>, shopper_fees: float, item_prices: array<int, float>}
     */
    protected function invoiceData(): array
    {
        return [
            'image'            => $this->file('invoice_image'),
            'pickup_address_id' => $this->validated('pickup_address_id') !== null ? (int) $this->validated('pickup_address_id') : null,
            'pickup_address'    => $this->validated('pickup_address') !== null
                ? array_intersect_key($this->validated('pickup_address'), array_flip(self::PICKUP_ADDRESS_FIELDS))
                : null,
            'shopper_fees'     => (float) $this->validated('shopper_fees'),
            'item_prices'      => $this->validatedItemPrices(),
        ];
    }

    /**
     * Price paid per unit, keyed by item id.
     *
     * @return array<int, float>
     */
    protected function validatedItemPrices(): array
    {
        return collect($this->validated('items') ?? [])
            ->mapWithKeys(fn (array $item) => [(int) $item['id'] => (float) $item['unit_price']])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    protected function invoiceMessages(): array
    {
        return [
            'pickup_address_id.exists'           => __('custom_orders.address_not_found'),
            'pickup_address_id.required_without' => __('custom_orders.pickup_address_required'),
            'pickup_address.required_without'    => __('custom_orders.pickup_address_required'),
            'items.*.id.exists' => __('custom_orders.item_not_in_order'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function invoiceAttributes(): array
    {
        $pickupFields = collect(self::PICKUP_ADDRESS_FIELDS)
            ->mapWithKeys(fn (string $field) => ['pickup_address.'.$field => __('validation.attributes.'.$field)])
            ->all();

        return [
            'invoice_image'      => __('validation.attributes.invoice_image'),
            'pickup_address_id'  => __('validation.attributes.pickup_address_id'),
            'pickup_address'     => __('validation.attributes.pickup_address_id'),
            'shopper_fees'       => __('validation.attributes.shopper_fees'),
            'items'              => __('validation.attributes.items'),
            'items.*.id'         => __('validation.attributes.item_id'),
            'items.*.unit_price' => __('validation.attributes.unit_price'),
        ] + $pickupFields;
    }
}
