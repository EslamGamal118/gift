<?php

namespace App\Http\Requests\Home;

use App\Http\Requests\Catalog\Concerns\ResolvesCustomerLocation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /home?latitude=..&longitude=..   (device GPS)
 * GET /home?city=riyadh                (manual / fallback city)
 * GET /home                            (no location: last GPS position or saved address
 *                                       when signed in, else default city)
 *
 * Works for guests and authenticated users alike.
 */
class HomeRequest extends FormRequest
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
        return [
            'within_km' => ['nullable', 'numeric', 'min:0.1', 'max:500'],
        ] + $this->locationRules();
    }

    /**
     * A signed-in customer's GPS position is cached so the next home load
     * without coordinates still uses it. Guests rely on what they send.
     */
    protected function remembersLocation(): bool
    {
        return true;
    }

    public function radiusKm(): ?float
    {
        return $this->filled('within_km') ? (float) $this->validated('within_km') : null;
    }

    public function attributes(): array
    {
        return ['within_km' => __('validation.attributes.within_km')] + $this->locationAttributes();
    }
}
