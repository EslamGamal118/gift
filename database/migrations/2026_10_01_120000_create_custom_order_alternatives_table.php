<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alternatives a personal shopper suggests for an unavailable item
 * (اقتراح بديل). The original item is kept untouched; the customer accepts
 * or rejects each suggestion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_order_alternatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('custom_order_item_id')->constrained('custom_order_items')->cascadeOnDelete();
            $table->foreignId('shopper_id')->constrained('users')->cascadeOnDelete();
            $table->string('product_name', 150);
            $table->decimal('price', 10, 2);
            $table->string('currency', 3)->default('SAR');
            $table->string('reason', 255);
            $table->string('image_disk', 30)->default('public');
            $table->string('image_path')->nullable();
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['custom_order_id', 'status']);
            $table->index('custom_order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_order_alternatives');
    }
};
