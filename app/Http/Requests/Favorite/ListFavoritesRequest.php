<?php

namespace App\Http\Requests\Favorite;

use App\Http\Requests\Catalog\Concerns\ResolvesCustomerLocation;
use App\Models\Favorite;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /favorites?type=store|product[&latitude=..&longitude=..|city=..][&per_page=..]
 *
 * The optional location only adds distance / delivery estimates to store cards.
 */
class ListFavoritesRequest extends FormRequest
{
    use ResolvesCustomerLocation;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['type' => strtolower(trim((string) $this->input('type', Favorite::TYPE_PRODUCT)))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type'     => ['required', 'string', Rule::in(array_keys(Favorite::TYPES))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ] + $this->locationRules();
    }

    public function favoritableType(): string
    {
        return (string) $this->validated('type');
    }

    public function attributes(): array
    {
        return ['type' => __('validation.attributes.type')] + $this->locationAttributes();
    }
}
