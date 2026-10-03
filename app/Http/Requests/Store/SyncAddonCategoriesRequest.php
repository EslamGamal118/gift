<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Replace the set of categories linked to an add-on.
 */
class SyncAddonCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('category_ids')) {
            $this->merge([
                'category_ids' => StoreAddonRequest::normalizeCategoryIds($this->input('category_ids')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return StoreAddonRequest::categoryIdsRules('required');
    }

    /**
     * @return array<int, int>
     */
    public function categoryIds(): array
    {
        return array_map('intval', $this->validated('category_ids'));
    }

    public function attributes(): array
    {
        return [
            'category_ids'   => __('validation.attributes.category_ids'),
            'category_ids.*' => __('validation.attributes.category_id'),
        ];
    }
}
