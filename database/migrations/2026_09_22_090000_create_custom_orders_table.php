<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom orders ("personal shopper"): a customer describes items they want
 * bought on their behalf, picks a shopper (or opens bidding), and sets where
 * and when to deliver. Address fields are snapshots taken at submission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 30)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();                 // customer
            $table->foreignId('shopper_id')->nullable()->constrained('users')->nullOnDelete(); // personal shopper account

            // How the shopper is chosen: the customer picked one, or shoppers bid for it
            $table->enum('assignment_mode', ['direct', 'bidding'])->nullable();
            $table->timestamp('bidding_opened_at')->nullable();

            // Where to deliver, and where the shopper buys / picks up (saved addresses; the
            // delivery fields below are a snapshot, the saved address may change or be deleted)
            $table->foreignId('delivery_address_id')->nullable()->constrained('user_addresses')->nullOnDelete();
            $table->foreignId('pickup_address_id')->nullable()->constrained('user_addresses')->nullOnDelete();
            $table->string('delivery_name')->nullable();
            $table->string('delivery_phone', 20)->nullable();
            $table->string('delivery_location_name', 100)->nullable();
            // The address is collected when the customer confirms the draft
            $table->string('delivery_city', 100)->nullable();
            $table->string('delivery_district', 100)->nullable();
            $table->string('delivery_street', 150)->nullable();
            $table->string('delivery_building_number', 30)->nullable();
            $table->string('delivery_address', 500)->nullable();           // single-line rendering
            $table->decimal('delivery_latitude', 10, 8)->nullable();
            $table->decimal('delivery_longitude', 11, 8)->nullable();

            // When the customer wants it delivered: an exact time, or a date + slot (snapshotted)
            $table->dateTime('delivery_at')->nullable();
            $table->date('delivery_date')->nullable();
            $table->foreignId('delivery_slot_id')->nullable()->constrained('delivery_slots')->nullOnDelete();
            $table->string('delivery_slot_label', 100)->nullable();        // e.g. "9:00 AM - 11:00 AM"
            $table->dateTime('delivery_window_start')->nullable();
            $table->dateTime('delivery_window_end')->nullable();
            $table->text('notes')->nullable();
            $table->text('confirmation_notes')->nullable();                // delivery instructions entered on confirm

            // Total expected budget (sum of the items' expected ranges unless the customer overrides it)
            $table->string('currency', 3)->default('SAR');
            $table->decimal('budget_min', 10, 2)->nullable();
            $table->decimal('budget_max', 10, 2)->nullable();
            $table->decimal('final_amount', 10, 2)->nullable();            // what the shopper actually spent

            // The shopper's purchase invoice and the priced bill the customer pays
            $table->string('invoice_disk', 30)->nullable();
            $table->string('invoice_path')->nullable();
            $table->timestamp('invoice_submitted_at')->nullable();
            $table->decimal('delivery_fee', 10, 2)->nullable();
            $table->decimal('shopper_fees', 10, 2)->nullable();
            $table->decimal('tax_amount', 10, 2)->nullable();              // VAT on items + fees + delivery
            $table->decimal('total_amount', 10, 2)->nullable();

            // Payment of that bill (same gateways as store orders)
            $table->string('payment_status', 20)->default('pending');
            $table->string('payment_method', 30)->nullable();
            $table->json('payment_data')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->enum('status', [
                'draft', 'pending', 'accepted', 'in_progress', 'waiting_for_alternative', 'waiting_for_payment', 'completed', 'cancelled',
            ])->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('purchased_at')->nullable();                // shopper finished buying
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->enum('cancelled_by', ['customer', 'shopper', 'system'])->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['shopper_id', 'status']);
            $table->index(['status', 'assignment_mode']);
            $table->index(['user_id', 'payment_status']);
        });

        Schema::create('custom_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_order_id')->constrained()->cascadeOnDelete();
            $table->string('product_name', 150);
            $table->text('description')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('expected_price_min', 10, 2)->nullable();
            $table->decimal('expected_price_max', 10, 2)->nullable();
            $table->decimal('unit_price', 10, 2)->nullable();               // price the shopper actually paid
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['custom_order_id', 'sort_order']);
        });

        // Reference images / attachments per item (multiple per item)
        Schema::create('custom_order_item_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_order_item_id')->constrained('custom_order_items')->cascadeOnDelete();
            $table->string('disk', 30)->default('public');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();               // bytes
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['custom_order_item_id', 'sort_order']);
        });

        // Offers from shoppers when the customer opens the order for bidding
        Schema::create('custom_order_bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shopper_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);                              // proposed total (items + service)
            $table->decimal('service_fee', 10, 2)->default(0);
            $table->dateTime('delivery_at')->nullable();                   // proposed delivery time
            $table->text('message')->nullable();
            $table->enum('status', ['pending', 'accepted', 'rejected', 'withdrawn'])->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['custom_order_id', 'shopper_id']);
            $table->index(['shopper_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_order_bids');
        Schema::dropIfExists('custom_order_item_media');
        Schema::dropIfExists('custom_order_items');
        Schema::dropIfExists('custom_orders');
    }
};
