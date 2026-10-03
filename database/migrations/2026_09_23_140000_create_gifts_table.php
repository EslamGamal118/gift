<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Online gifts (products of a special category) bought for someone else.
     *
     * Each gift rides on an `orders` row (one line, no delivery) so payment,
     * gateways, webhooks and the store's order workflow are shared with regular
     * orders; this table holds the recipient, delivery-by-WhatsApp and claim state.
     */
    public function up(): void
    {
        Schema::create('gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('users')->cascadeOnDelete();     // merchant account
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();  // name kept on the order line

            // Recipient: snapshot, then the account it is attached to (now or at sign-up)
            $table->string('recipient_name', 100);
            $table->string('recipient_phone', 20);                 // normalized, e.g. 9665XXXXXXXX
            $table->string('recipient_email')->nullable();
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('gift_message')->nullable();

            // Mirrors the order's payment so gift screens need no join
            $table->enum('payment_status', ['pending', 'paid', 'failed', 'cancelled', 'refunded'])->default('pending');
            $table->string('payment_gateway', 30)->nullable();      // alrajhi | tamara | tabby
            $table->timestamp('paid_at')->nullable();

            // Claiming: automatic for the account with the recipient's phone, else the
            // 6-digit `claim_pin` texted to that phone (POST /gifts/claim)
            $table->boolean('is_claimed')->default(false);
            $table->string('claim_code', 16)->unique();             // in the WhatsApp link
            $table->string('claim_pin', 6)->nullable();
            // The recipient's QR code, scanned by the store; never shown to the sender
            $table->string('redemption_code', 32)->nullable()->unique();
            $table->timestamp('claimed_at')->nullable();

            // Home screen popup: opened in the app / "Not now"
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('popup_dismissed_at')->nullable();

            // Redemption at the store: valid until paid_at + validity days
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();

            $table->enum('whatsapp_status', ['pending', 'sent', 'failed', 'skipped'])->default('pending');
            $table->timestamp('whatsapp_sent_at')->nullable();
            $table->string('whatsapp_error')->nullable();

            $table->string('sms_status', 10)->default('pending');  // claim code SMS: pending | sent | failed | skipped
            $table->timestamp('sms_sent_at')->nullable();

            $table->timestamps();

            // Auto-claim at sign-up: paid, unclaimed gifts for a phone number
            $table->index(['recipient_phone', 'is_claimed', 'payment_status']);
            $table->index(['sender_id', 'created_at']);
            $table->index(['recipient_id', 'created_at']);
            $table->index(['recipient_id', 'opened_at']);
            $table->index(['recipient_id', 'redeemed_at', 'expires_at']);
            $table->index(['claim_pin', 'is_claimed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gifts');
    }
};
