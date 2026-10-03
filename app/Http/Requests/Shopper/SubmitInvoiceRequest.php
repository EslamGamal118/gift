<?php

namespace App\Http\Requests\Shopper;

use App\Http\Requests\Shopper\Concerns\ValidatesInvoice;

/**
 * POST /shopper/orders/{customOrder}/submit-invoice-prices  (multipart)
 * { invoice_image, pickup_address_id | pickup_address{...}, shopper_fees, items: [{ id, unit_price }] }
 *
 * The invoice form on its own (see ValidatesInvoice); it can also be sent
 * with the status update. Whether the order accepts an invoice in its
 * current status is checked by ShopperOrderService (409).
 */
class SubmitInvoiceRequest extends ShopperOrderActionRequest
{
    use ValidatesInvoice;

    protected function prepareForValidation(): void
    {
        $this->normalizeInvoiceInput();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->invoiceRules();
    }

    public function messages(): array
    {
        return $this->invoiceMessages();
    }

    /**
     * @return array{image: \Illuminate\Http\UploadedFile, pickup_address_id: ?int, pickup_address: ?array<string, mixed>, shopper_fees: float, item_prices: array<int, float>}
     */
    public function invoice(): array
    {
        return $this->invoiceData();
    }

    public function attributes(): array
    {
        return $this->invoiceAttributes();
    }
}
