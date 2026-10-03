<?php

namespace App\Http\Requests\CustomOrder;

use App\Models\CustomOrder;
use App\Models\CustomOrderAlternative;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /custom-orders/{customOrder}/alternatives/response
 * { alternatives: [{ id, is_approved: bool }] }
 *
 * `status: approved | accepted | rejected | declined` is accepted instead of
 * `is_approved`. Each id must be a still-pending alternative of this order
 * (once). Another customer's order is a 404.
 */
class RespondToAlternativesRequest extends FormRequest
{
    public const APPROVED = ['approved', 'approve', 'accepted', 'accept'];

    public const REJECTED = ['rejected', 'reject', 'declined', 'decline'];

    protected ?CustomOrder $customOrder = null;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function order(): CustomOrder
    {
        return $this->customOrder ??= CustomOrder::query()
            ->forCustomer($this->user()->id)
            ->findOrFail((int) $this->route('customOrder'));
    }

    protected function prepareForValidation(): void
    {
        if (! is_array($alternatives = $this->input('alternatives'))) {
            return;
        }

        $this->merge(['alternatives' => array_map(function ($alternative) {
            if (! is_array($alternative) || array_key_exists('is_approved', $alternative) || ! isset($alternative['status'])) {
                return $alternative;
            }

            $status = strtolower(trim((string) $alternative['status']));

            return $alternative + ['is_approved' => match (true) {
                in_array($status, self::APPROVED, true) => true,
                in_array($status, self::REJECTED, true) => false,
                default                                 => $alternative['status'],   // fails the boolean rule
            }];
        }, $alternatives)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'alternatives'               => ['required', 'array', 'min:1'],
            'alternatives.*.id'          => [
                'required', 'integer', 'distinct',
                Rule::exists('custom_order_alternatives', 'id')
                    ->where('custom_order_id', $this->order()->id)
                    ->where('status', CustomOrderAlternative::STATUS_PENDING),
            ],
            'alternatives.*.is_approved' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'alternatives.*.id.exists' => __('custom_orders.alternative_not_pending'),
        ];
    }

    /**
     * @return array<int, bool>  alternative id => approved
     */
    public function decisions(): array
    {
        return collect($this->validated('alternatives'))
            ->mapWithKeys(fn (array $alternative) => [(int) $alternative['id'] => (bool) $alternative['is_approved']])
            ->all();
    }

    public function attributes(): array
    {
        return [
            'alternatives'               => __('validation.attributes.alternatives'),
            'alternatives.*.id'          => __('validation.attributes.alternative_id'),
            'alternatives.*.is_approved' => __('validation.attributes.is_approved'),
        ];
    }
}
