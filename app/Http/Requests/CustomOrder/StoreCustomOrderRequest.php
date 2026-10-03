<?php

namespace App\Http\Requests\CustomOrder;

use App\Http\Requests\CustomOrder\Concerns\ValidatesDeliveryAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Step 1: create a custom (personal shopper) order as a draft.
 *
 * POST /custom-orders  (multipart/form-data)
 *
 *   items[0][product_name]        required
 *   items[0][description]
 *   items[0][quantity]            default 1
 *   items[0][expected_price_min]
 *   items[0][expected_price_max]  >= expected_price_min
 *   items[0][images][]            up to N image files
 *   budget_min / budget_max       optional override of the summed item ranges
 *   shopper_id                    optional: choose this shopper straight away (step 2 already done)
 *   notes
 *
 * Optional here, normally collected on the confirm step (step 3):
 *   address_id | address[...]     delivery address (see ValidatesDeliveryAddress)
 *   delivery_at                   "Y-m-d H:i" within the configured lead window
 */
class StoreCustomOrderRequest extends FormRequest
{
    use ValidatesDeliveryAddress;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $merge = $this->normalizedAddressInput();

        if (is_string($this->input('notes'))) {
            $merge['notes'] = trim($this->input('notes')) ?: null;
        }

        $items = $this->input('items');

        if (is_array($items)) {
            foreach ($items as $key => $item) {
                if (! is_array($item)) {
                    continue;
                }

                foreach (['product_name', 'description'] as $field) {
                    if (is_string($item[$field] ?? null)) {
                        $items[$key][$field] = trim($item[$field]) ?: null;
                    }
                }

                // Blank optional numbers from multipart forms arrive as "" - treat as absent
                foreach (['expected_price_min', 'expected_price_max', 'quantity'] as $field) {
                    if (($item[$field] ?? null) === '') {
                        $items[$key][$field] = null;
                    }
                }
            }

            $merge['items'] = array_values($items);
        }

        foreach (['budget_min', 'budget_max', 'shopper_id', 'delivery_at'] as $field) {
            if ($this->input($field) === '') {
                $merge[$field] = null;
            }
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $limits   = config('custom_orders.items');
        $delivery = config('custom_orders.delivery');

        $earliest = now()->addMinutes((int) $delivery['min_lead_minutes'])->format('Y-m-d H:i:s');
        $latest   = now()->addDays((int) $delivery['max_lead_days'])->format('Y-m-d H:i:s');

        return [
            'items'                      => ['required', 'array', 'min:1', 'max:'.$limits['max_items']],
            'items.*.product_name'       => ['required', 'string', 'min:2', 'max:150'],
            'items.*.description'        => ['nullable', 'string', 'max:1000'],
            'items.*.quantity'           => ['nullable', 'integer', 'min:1', 'max:'.$limits['max_quantity']],
            'items.*.expected_price_min' => ['nullable', 'numeric', 'min:0', 'max:'.$limits['max_price']],
            'items.*.expected_price_max' => ['nullable', 'numeric', 'min:0', 'max:'.$limits['max_price'], 'gte:items.*.expected_price_min'],
            'items.*.images'             => ['nullable', 'array', 'max:'.$limits['max_images']],
            'items.*.images.*'           => ['file', 'image', 'mimes:'.implode(',', $limits['image_mimes']), 'max:'.$limits['max_image_kb']],

            'delivery_at' => ['nullable', 'date_format:Y-m-d H:i', 'after_or_equal:'.$earliest, 'before_or_equal:'.$latest],
            'notes'       => ['nullable', 'string', 'max:1000'],

            'budget_min'  => ['nullable', 'numeric', 'min:0', 'max:'.$limits['max_price']],
            'budget_max'  => ['nullable', 'numeric', 'min:0', 'max:'.$limits['max_price'], 'gte:budget_min'],

            // Shopper chosen in step 2 (validated for availability by the service)
            'shopper_id'  => ['nullable', 'integer', Rule::exists('users', 'id')->where('user_type', 'shopper')],
        ] + $this->addressRules(required: false);
    }

    /**
     * Uploaded reference images keyed by item index, ready for the service.
     *
     * @return array<int, array<int, UploadedFile>>
     */
    public function itemImages(): array
    {
        $images = [];

        foreach ((array) $this->file('items', []) as $index => $item) {
            $files = array_filter((array) ($item['images'] ?? []), fn ($f) => $f instanceof UploadedFile);

            if ($files !== []) {
                $images[(int) $index] = array_values($files);
            }
        }

        return $images;
    }

    /**
     * Validated payload without the file objects.
     *
     * @return array<string, mixed>
     */
    public function orderData(): array
    {
        $data = $this->validated();

        foreach ($data['items'] as &$item) {
            unset($item['images']);
        }

        return $data;
    }

    public function attributes(): array
    {
        $keys = [
            'items', 'items.*.product_name', 'items.*.description', 'items.*.quantity',
            'items.*.expected_price_min', 'items.*.expected_price_max', 'items.*.images', 'items.*.images.*',
            ...$this->addressAttributeKeys(),
            'delivery_at', 'notes', 'budget_min', 'budget_max', 'shopper_id',
        ];

        return array_combine($keys, array_map(fn ($k) => __('validation.attributes.'.$k), $keys));
    }

    public function messages(): array
    {
        $delivery = config('custom_orders.delivery');

        return $this->addressMessages() + [
            'items.*.expected_price_max.gte' => __('custom_orders.price_range_invalid'),
            'budget_max.gte'                 => __('custom_orders.budget_range_invalid'),
            'delivery_at.after_or_equal'     => __('custom_orders.delivery_too_soon', ['minutes' => $delivery['min_lead_minutes']]),
            'delivery_at.before_or_equal'    => __('custom_orders.delivery_too_far', ['days' => $delivery['max_lead_days']]),
        ];
    }
}
