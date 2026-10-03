<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail of every gateway interaction (session creation, callbacks,
     * webhooks, captures). `gateway_reference` is the gateway's own id for the
     * payment so webhooks can be matched back to what was paid: a store order,
     * a custom order or a multi-store checkout (exactly one of the three).
     */
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('custom_order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('checkout_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('gateway', 30);                          // alrajhi | tamara | tabby
            $table->string('gateway_reference', 100)->nullable();   // payment / session id at the gateway
            $table->string('event', 50);                            // session_created | callback | webhook | capture ...
            $table->enum('status', ['initiated', 'authorized', 'captured', 'failed', 'cancelled', 'refunded']);
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['gateway', 'gateway_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
