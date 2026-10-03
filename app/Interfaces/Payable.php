<?php

namespace App\Interfaces;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something the payment gateways (AlRajhi / Tamara / Tabby) can charge: a
 * store order or a custom (personal shopper) order. PaymentService and the
 * gateway services only talk to it through this contract, plus the shared
 * columns every payable has: `payment_method`, `payment_status`,
 * `payment_data` (array), `paid_at`, `total_amount`, `user_id`.
 *
 * @property string|null $payment_method
 * @property string $payment_status
 * @property array|null $payment_data
 * @property string|null $total_amount
 * @property int $user_id
 */
interface Payable
{
    /**
     * Identifies the payable in gateway references, return URLs and callbacks
     * (resolved back by PayableResolver): "15" for store order 15, "CO-15"
     * for custom order 15.
     */
    public function paymentKey(): string;

    /**
     * `order` | `custom_order`, for API responses.
     */
    public function paymentType(): string;

    /**
     * Human reference shown at the gateway (the order number).
     */
    public function paymentReference(): string;

    public function paymentAmount(): float;

    public function paymentCurrency(): string;

    public function paymentTaxAmount(): float;

    public function paymentShippingAmount(): float;

    public function paymentDiscountAmount(): float;

    /**
     * Contact and address of the buyer as known on the order.
     *
     * @return array{name: ?string, phone: ?string, email: ?string, city: ?string, region: ?string, address: ?string}
     */
    public function paymentBuyer(): array;

    /**
     * The account paying.
     */
    public function paymentCustomer(): ?User;

    /**
     * What is being paid for, for the gateways' line items.
     *
     * @return list<array{reference: string, name: string, quantity: int, unit_price: float, total: float, image_url: ?string}>
     */
    public function paymentLines(): array;

    public function isPaid(): bool;

    /**
     * Whether a payment may be started now.
     */
    public function isPayable(): bool;

    /**
     * Closed for payment (e.g. cancelled): a late capture is only logged.
     */
    public function isPaymentClosed(): bool;

    /**
     * Fill (not save) the paid state.
     */
    public function markPaid(?string $gateway, ?string $reference): void;

    /**
     * Fill (not save) a failed attempt; the payable stays payable.
     */
    public function markPaymentFailed(): void;

    /**
     * @param  array<string, mixed>  $data
     */
    public function mergePaymentData(array $data): static;

    /**
     * Logged gateway interactions (payment_transactions).
     */
    public function transactions(): HasMany;
}
