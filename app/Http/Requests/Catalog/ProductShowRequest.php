<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\Catalog\Concerns\ResolvesCustomerLocation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Product details screen.
 *
 * GET /products/{product}?latitude=..&longitude=..
 *
 * Coordinates (or a city) are optional; without them the distance and the
 * travel part of the delivery estimate are simply omitted. An authenticated
 * customer's default address is used as a fallback position.
 */
class ProductShowRequest extends FormRequest
{
    use ResolvesCustomerLocation;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->locationRules();
    }

    public function attributes(): array
    {
        return $this->locationAttributes();
    }
}
