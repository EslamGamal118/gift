<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-vendor checkout: a cart may hold products of several stores. Paying
 * for it goes through one `checkout_groups` row (what the customer pays,
 * once); `snapshot` freezes the cart when the payment starts, and one
 * independent order per store (`orders.checkout_group_id`) is created from it
 * once the gateway confirms the payment. Gateway transactions of a
 * multi-store payment belong to the group (`payment_transactions.checkout_group_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_groups', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();   // shown at the gateway, e.g. CHK-20261003-7K3D9Q
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending_payment');   // pending_payment | paid | cancelled

            // Sums of the child orders (the amount charged)
            $table->string('currency', 3)->default('SAR');
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('express_fee', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);

            $table->string('payment_status', 20)->default('pending');
            $table->string('payment_method', 30)->nullable();
            $table->json('payment_data')->nullable();
            // Frozen cart (lines, prices, address, delivery, promo per store) the orders are made of
            $table->json('snapshot')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_groups');
    }
};
