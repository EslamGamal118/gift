<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Create an add-on for the authenticated merchant's store and link it to
 * the categories whose products may use it.
 */
class StoreAddonRequest extends FormRequest
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

        if ($this->filled('name')) {
            $merge['name'] = trim((string) $this->input('name'));
        }

        if ($this->has('category_ids')) {
            $merge['category_ids'] = self::normalizeCategoryIds($this->input('category_ids'));
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
            'name'           => [$presence, 'string', 'min:2', 'max:100'],
            'price'          => [$presence, 'numeric', 'min:0', 'max:'.self::MAX_PRICE],
            'stock_quantity' => [$presence, 'integer', 'min:0', 'max:1000000'],
            'is_active'      => ['sometimes', 'boolean'],
            'image'          => ['nullable', ...self::IMAGE_RULES],
        ] + self::categoryIdsRules($presence);
    }

    /**
     * Rules for the list of categories an add-on is linked to (shared with the sync endpoint).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function categoryIdsRules(string $presence): array
    {
        return [
            'category_ids'   => [$presence, 'array', 'min:1', 'max:50'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('is_active', true)],
        ];
    }

    /**
     * Accept `category_ids` as an array, a JSON string or a comma-separated list
     * (the latter two are common in multipart requests).
     *
     * @return array<int, mixed>|mixed
     */
    public static function normalizeCategoryIds(mixed $ids): mixed
    {
        if (! is_string($ids)) {
            return $ids;
        }

        $decoded = json_decode($ids, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        return array_values(array_filter(array_map('trim', explode(',', $ids)), 'strlen'));
    }

    /**
     * Attributes ready to be persisted (excluding the image file and category links).
     *
     * @return array<string, mixed>
     */
    public function addonData(): array
    {
        return $this->safe()->except(['image', 'category_ids']);
    }

    /**
     * @return array<int, int>|null  Null when the categories were not sent (partial update).
     */
    public function categoryIds(): ?array
    {
        if (! $this->has('category_ids')) {
            return null;
        }

        return array_map('intval', $this->validated('category_ids', []));
    }

    public function imageFile(): ?UploadedFile
    {
        return $this->file('image');
    }

    public function attributes(): array
    {
        return [
            'name'           => __('validation.attributes.addon_name'),
            'price'          => __('validation.attributes.price'),
            'stock_quantity' => __('validation.attributes.stock_quantity'),
            'is_active'      => __('validation.attributes.is_active'),
            'image'          => __('validation.attributes.addon_image'),
            'category_ids'   => __('validation.attributes.category_ids'),
            'category_ids.*' => __('validation.attributes.category_id'),
        ];
    }
}
