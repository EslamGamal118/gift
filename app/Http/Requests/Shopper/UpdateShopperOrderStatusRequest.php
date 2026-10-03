<?php

namespace App\Http\Requests\Shopper;

use App\Http\Requests\Shopper\Concerns\ValidatesInvoice;
use App\Models\CustomOrder;
use App\Services\ShopperOrderService;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * POST /shopper/orders/{customOrder}/status  (JSON or multipart)
 * { status: accepted|in_progress|waiting_for_payment|cancelled, cancellation_reason?, items?: [{ id, unit_price }],
 *   invoice_image?, pickup_address_id? | pickup_address{...}?, shopper_fees? }
 *
 * `purchased` (and `completed`, its former name) is accepted for
 * `waiting_for_payment` (تم الشراء: the customer pays next), `started` for
 * `in_progress`, and `reject` / `rejected` / `cancel` / `canceled` /
 * `decline` / `declined` for `cancelled` (رفض / إلغاء الطلب).
 *
 * Two ways to complete the order:
 *  1. Status only ({ status: purchased }): nothing else is required.
 *     `items` (prices paid) may still be sent and are recorded.
 *  2. With the invoice form (إضافة الأسعار والفاتورة): as soon as
 *     `invoice_image`, `pickup_address_id`, `pickup_address` or `shopper_fees` is sent, the
 *     whole form is required (see ValidatesInvoice). This also works for an
 *     order already completed in step 1.
 *
 * `final_amount` is never taken from the client (it is computed by
 * ShopperOrderService); a value sent by older apps is ignored.
 *
 * `cancellation_reason` is required when cancelling (the customer is told
 * why). Pricing and invoice fields are ignored for any other status. Whether
 * the step is allowed from the current status is checked by
 * ShopperOrderService (409 otherwise).
 */
class UpdateShopperOrderStatusRequest extends ShopperOrderActionRequest
{
    use ValidatesInvoice;

    /**
     * @var array<string, string>
     */
    public const ALIASES = [
        'purchased' => CustomOrder::STATUS_WAITING_FOR_PAYMENT,
        'completed' => CustomOrder::STATUS_WAITING_FOR_PAYMENT,   // older apps; only the payment completes an order
        'started'   => CustomOrder::STATUS_IN_PROGRESS,
        'reject'    => CustomOrder::STATUS_CANCELLED,
        'rejected'  => CustomOrder::STATUS_CANCELLED,
        'cancel'    => CustomOrder::STATUS_CANCELLED,
        'canceled'  => CustomOrder::STATUS_CANCELLED,
        'decline'   => CustomOrder::STATUS_CANCELLED,
        'declined'  => CustomOrder::STATUS_CANCELLED,
    ];

    /**
     * Any of these turns a completion into an invoice submission.
     *
     * @var list<string>
     */
    public const INVOICE_FIELDS = ['invoice_image', 'pickup_address_id', 'pickup_address', 'shopper_fees'];

    protected function prepareForValidation(): void
    {
        $status = strtolower(trim((string) $this->input('status')));

        $this->merge(array_filter([
            'status'              => self::ALIASES[$status] ?? $status,
            'cancellation_reason' => $this->filled('cancellation_reason') ? trim((string) $this->input('cancellation_reason')) : null,
        ], fn ($value) => $value !== null));

        $this->normalizeInvoiceInput();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'status'              => ['required', Rule::in(ShopperOrderService::ACTION_STATUSES)],
            'cancellation_reason' => ['nullable', 'required_if:status,'.CustomOrder::STATUS_CANCELLED, 'string', 'min:3', 'max:500'],
            'final_amount'        => ['exclude'],
        ];

        if (! $this->isCompleting()) {
            return $rules + array_fill_keys(['items', ...self::INVOICE_FIELDS], ['exclude']);
        }

        if ($this->withInvoice()) {
            return $rules + $this->invoiceRules();
        }

        // Step 1: status only; prices may come along but nothing is required
        return $rules + array_fill_keys(self::INVOICE_FIELDS, ['exclude']) + [
            'items' => ['nullable', 'array'],
        ] + Arr::only($this->invoiceRules(), ['items.*.id', 'items.*.unit_price']);
    }

    public function status(): string
    {
        return $this->validated('status');
    }

    public function isCompleting(): bool
    {
        return $this->input('status') === CustomOrder::STATUS_WAITING_FOR_PAYMENT;
    }

    /**
     * Whether the invoice form came with the completion.
     */
    public function withInvoice(): bool
    {
        return $this->isCompleting()
            && collect(self::INVOICE_FIELDS)->contains(fn (string $field) => $this->hasFile($field) || $this->filled($field));
    }

    /**
     * The invoice form (step 2), or null for a status-only update.
     *
     * @return array{image: \Illuminate\Http\UploadedFile, pickup_address_id: ?int, pickup_address: ?array<string, mixed>, shopper_fees: float, item_prices: array<int, float>}|null
     */
    public function invoice(): ?array
    {
        return $this->withInvoice() ? $this->invoiceData() : null;
    }


    /**
     * Price paid per unit, keyed by item id. Only meaningful when completing.
     *
     * @return array<int, float>
     */
    public function itemPrices(): array
    {
        return $this->isCompleting() ? $this->validatedItemPrices() : [];
    }

    /**
     * Only meaningful when cancelling.
     */
    public function cancellationReason(): ?string
    {
        return $this->status() === CustomOrder::STATUS_CANCELLED ? $this->validated('cancellation_reason') : null;
    }

    public function messages(): array
    {
        return $this->invoiceMessages();
    }

    public function attributes(): array
    {
        return [
            'status'              => __('validation.attributes.status'),
            'cancellation_reason' => __('validation.attributes.cancellation_reason'),
        ] + $this->invoiceAttributes();
    }
}
