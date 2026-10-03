<?php

namespace App\Http\Requests\Store;

/**
 * Update a product. Every field is optional so partial updates are allowed.
 */
class UpdateProductRequest extends StoreProductRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->baseRules('sometimes');
    }
}
