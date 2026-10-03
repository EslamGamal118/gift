<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One active cart per customer. Besides the items it keeps the checkout
     * selections (address, delivery slot, promo code, gift message) so the
     * customer can move between screens and resume later.
     */
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            // May hold products of several stores (split into one order per store at checkout)
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Checkout selections
            $table->foreignId('address_id')->nullable()->constrained('user_addresses')->nullOnDelete();
            $table->enum('delivery_type', ['scheduled', 'instant'])->nullable();
            $table->date('delivery_date')->nullable();
            $table->foreignId('delivery_slot_id')->nullable()->constrained('delivery_slots')->nullOnDelete();
            $table->foreignId('promo_code_id')->nullable()->constrained('promo_codes')->nullOnDelete();
            $table->text('gift_message')->nullable();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['cart_id', 'product_id']);
        });

        // Add-ons chosen for a cart line (priced live from the addons table until checkout).
        Schema::create('cart_item_addon', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_item_id')->constrained('cart_items')->cascadeOnDelete();
            $table->foreignId('addon_id')->constrained('addons')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['cart_item_id', 'addon_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_item_addon');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
