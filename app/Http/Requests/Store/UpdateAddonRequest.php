<?php

namespace App\Http\Requests\Store;

/**
 * Update an add-on. Every field is optional so partial updates are allowed;
 * when `category_ids` is sent, the category links are replaced with the given set.
 */
class UpdateAddonRequest extends StoreAddonRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->baseRules('sometimes');
    }
}
