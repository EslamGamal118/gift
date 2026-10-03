<?php

namespace App\Http\Requests\Gift;

use App\Http\Requests\Gift\Concerns\ValidatesGiftSelection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /gifts/details/{product}?quantity=1&addon_ids[]=3&promo_code=GIFT10
 *
 * The product (route) must belong to an active special (online gifts)
 * category. The query string is the screen's current selection, priced into
 * the financial summary exactly as POST /gifts/checkout will charge it.
 */
class GiftDetailsRequest extends FormRequest
{
    use ValidatesGiftSelection;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['product_id' => $this->route('product')]);
        $this->prepareGiftSelection();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->giftSelectionRules();
    }

    public function attributes(): array
    {
        return $this->giftSelectionAttributes();
    }
}
