<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Create a product for the authenticated merchant's store.
 */
class StoreProductRequest extends FormRequest
{
    public const MAX_IMAGE_KB = 2048;
    public const MAX_PRICE    = 99999999.99;

    protected const IMAGE_RULES = ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_IMAGE_KB];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['name', 'description'] as $field) {
            if (is_string($this->input($field))) {
                $merge[$field] = trim($this->input($field));
            }
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->baseRules('required');
    }

    /**
     * Shared rule set; `$presence` is `required` on create and `sometimes` on update.
     *
     * @return array<string, mixed>
     */
    protected function baseRules(string $presence): array
    {
        return [
            'name'             => [$presence, 'string', 'min:2', 'max:150'],
            'description'      => ['nullable', 'string', 'max:255'],
            'category_id'      => [$presence, 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'price'            => [$presence, 'numeric', 'min:0', 'max:'.self::MAX_PRICE],
            'stock_quantity'   => [$presence, 'integer', 'min:0', 'max:1000000'],
            'preparation_time' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'expiry_date'      => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'image'            => ['nullable', ...self::IMAGE_RULES],
        ];
    }

    /**
     * Attributes ready to be persisted (excluding the image file). Rating fields are
     * maintained by the reviews system and are never accepted from the client.
     *
     * @return array<string, mixed>
     */
    public function productData(): array
    {
        $data = $this->safe()->except(['image']);

        // Treat an explicitly empty description as "no description".
        if (array_key_exists('description', $data) && $data['description'] === '') {
            $data['description'] = null;
        }

        return $data;
    }

    public function imageFile(): ?UploadedFile
    {
        return $this->file('image');
    }

    public function attributes(): array
    {
        return [
            'name'             => __('validation.attributes.product_name'),
            'description'      => __('validation.attributes.description'),
            'category_id'      => __('validation.attributes.category_id'),
            'price'            => __('validation.attributes.price'),
            'stock_quantity'   => __('validation.attributes.stock_quantity'),
            'preparation_time' => __('validation.attributes.preparation_time'),
            'expiry_date'      => __('validation.attributes.expiry_date'),
            'image'            => __('validation.attributes.product_image'),
        ];
    }

    public function messages(): array
    {
        return [
            'expiry_date.after_or_equal' => __('store.expiry_date_in_past'),
        ];
    }
}
