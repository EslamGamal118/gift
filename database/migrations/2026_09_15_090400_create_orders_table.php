<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // The (possibly multi-store) checkout paid once; one order per store under it
            $table->foreignId('checkout_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_number', 30)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('captain_id')->nullable()->constrained('users')->nullOnDelete(); // set when a captain takes it

            // Delivery address snapshot (the address row may change or be deleted later)
            $table->foreignId('address_id')->nullable()->constrained('user_addresses')->nullOnDelete();
            $table->string('shipping_name')->nullable();
            $table->string('shipping_phone', 20)->nullable();
            $table->string('shipping_email')->nullable();
            $table->string('shipping_location_name', 100)->nullable();
            $table->string('shipping_city', 100);
            $table->string('shipping_district', 100);
            $table->string('shipping_street', 150);
            $table->string('shipping_building_number', 30);
            $table->string('shipping_address', 500);                 // single-line rendering of the fields above
            $table->decimal('shipping_latitude', 10, 8)->nullable();
            $table->decimal('shipping_longitude', 11, 8)->nullable();

            // Delivery schedule
            $table->enum('delivery_type', ['scheduled', 'instant']);
            $table->date('delivery_date')->nullable();
            $table->foreignId('delivery_slot_id')->nullable()->constrained('delivery_slots')->nullOnDelete();
            $table->string('delivery_slot_label', 100)->nullable();  // snapshot, e.g. "9:00 AM - 11:00 AM"
            $table->dateTime('delivery_window_start')->nullable();
            $table->dateTime('delivery_window_end')->nullable();
            $table->unsignedSmallInteger('estimated_minutes_min')->nullable(); // instant delivery ETA
            $table->unsignedSmallInteger('estimated_minutes_max')->nullable();

            $table->text('gift_message')->nullable();

            // Promo snapshot
            $table->foreignId('promo_code_id')->nullable()->constrained('promo_codes')->nullOnDelete();
            $table->string('promo_code', 50)->nullable();

            // Financials
            $table->string('currency', 3)->default('SAR');
            $table->decimal('subtotal', 10, 2);
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('express_fee', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('tax_rate', 5, 4)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2);

            // Payment
            $table->string('payment_method', 30)->nullable();        // alrajhi | tamara | tabby
            $table->enum('payment_status', ['pending', 'paid', 'failed', 'cancelled', 'refunded'])->default('pending');
            $table->json('payment_data')->nullable();                // gateway references
            $table->timestamp('paid_at')->nullable();

            // Store workflow timeline (see App\Support\OrderStateMachine)
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            // pending_payment -> pending -> accepted -> processing -> ready -> out_for_delivery -> delivered
            // (cancelled from pending / accepted / processing)
            $table->enum('status', [
                'pending_payment', 'pending', 'accepted', 'processing',
                'ready', 'out_for_delivery', 'delivered', 'cancelled',
            ])->default('pending_payment');
            $table->text('cancellation_reason')->nullable();
            $table->enum('cancelled_by', ['store', 'customer', 'captain', 'system'])->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['store_id', 'status']);
            $table->index('payment_status');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->string('product_image')->nullable();
            $table->decimal('unit_price', 10, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('addons_total', 10, 2)->default(0);      // add-ons for the whole line
            $table->decimal('subtotal', 10, 2);                      // unit_price * quantity + addons_total
            $table->timestamps();
        });

        Schema::create('order_item_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('addon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->decimal('unit_price', 10, 2);
            $table->unsignedInteger('quantity');                     // mirrors the line quantity
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();
        });

        // Audit trail of every status transition (who, when, why)
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->enum('actor_type', ['store', 'customer', 'captain', 'system'])->default('system');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_item_addons');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
