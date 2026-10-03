<?php

namespace App\Http\Requests\Favorite;

use App\Models\Favorite;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /favorites/toggle { favoritable_type: store|product, favoritable_id }
 */
class ToggleFavoriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('favoritable_type')) {
            $this->merge(['favoritable_type' => strtolower(trim((string) $this->input('favoritable_type')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'favoritable_type' => ['required', 'string', Rule::in(array_keys(Favorite::TYPES))],
            'favoritable_id'   => ['required', 'integer', 'min:1'],
        ];
    }

    public function favoritableType(): string
    {
        return (string) $this->validated('favoritable_type');
    }

    public function favoritableId(): int
    {
        return (int) $this->validated('favoritable_id');
    }

    public function attributes(): array
    {
        return [
            'favoritable_type' => __('validation.attributes.favoritable_type'),
            'favoritable_id'   => __('validation.attributes.favoritable_id'),
        ];
    }
}
