<?php

namespace App\Http\Requests\CustomOrder\Concerns;

use App\Support\PhoneNumber;
use Illuminate\Validation\Rule;

/**
 * The delivery address block shared by the create and confirm requests:
 * a saved address (`address_id`) or an inline `address[...]` object.
 *
 *   address_id                    one of the customer's saved addresses (or `delivery_address_id`)  - or -
 *   address[city|district|street|building_number|location_name|phone|latitude|longitude]
 */
trait ValidatesDeliveryAddress
{
    /**
     * Input to merge before validation: trimmed strings, blank optional
     * values as null, normalised phone.
     *
     * @return array<string, mixed>
     */
    protected function normalizedAddressInput(): array
    {
        $merge = [];

        // `delivery_address_id` (the column's name) is accepted for `address_id`
        if (! $this->has('address_id') && $this->has('delivery_address_id')) {
            $merge['address_id'] = $this->input('delivery_address_id');
        }

        if (($merge['address_id'] ?? $this->input('address_id')) === '') {
            $merge['address_id'] = null;
        }

        $address = $this->input('address');

        if (is_array($address)) {
            foreach (['location_name', 'city', 'district', 'street', 'building_number'] as $field) {
                if (is_string($address[$field] ?? null)) {
                    $address[$field] = trim($address[$field]) ?: null;
                }
            }

            if (filled($address['phone'] ?? null)) {
                $address['phone'] = PhoneNumber::normalize($address['phone']);
            }

            $merge['address'] = $address;
        }

        return $merge;
    }

    /**
     * @param  bool  $required  Whether one of `address_id` / `address` must be present
     * @return array<string, array<int, mixed>>
     */
    protected function addressRules(bool $required): array
    {
        return [
            'address_id'              => array_values(array_filter([
                'nullable', 'integer', $required ? 'required_without:address' : null,
                Rule::exists('user_addresses', 'id')->where('user_id', $this->user()?->id),
            ])),
            'address'                 => array_values(array_filter(['nullable', 'array', $required ? 'required_without:address_id' : null])),
            'address.location_name'   => ['nullable', 'string', 'max:100'],
            'address.city'            => ['required_with:address', 'string', 'min:2', 'max:100'],
            'address.district'        => ['nullable', 'string', 'max:100'],
            'address.street'          => ['nullable', 'string', 'max:150'],
            'address.building_number' => ['nullable', 'string', 'max:30'],
            'address.phone'           => ['nullable', 'string', 'regex:'.config('otp.phone_pattern')],
            'address.latitude'        => ['nullable', 'numeric', 'between:-90,90', 'required_with:address.longitude'],
            'address.longitude'       => ['nullable', 'numeric', 'between:-180,180', 'required_with:address.latitude'],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function addressAttributeKeys(): array
    {
        return [
            'address_id', 'address', 'address.location_name', 'address.city', 'address.district', 'address.street',
            'address.building_number', 'address.phone', 'address.latitude', 'address.longitude',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function addressMessages(): array
    {
        return ['address.phone.regex' => __('auth.invalid_phone')];
    }
}
