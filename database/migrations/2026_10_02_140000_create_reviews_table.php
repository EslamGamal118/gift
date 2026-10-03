<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer's review of a finished order, store order or custom order
 * alike (`reviewable_type` = order | custom_order, see Review::TYPES):
 * the store / shopper and the products, each 1-5 stars with an optional
 * comment. One review per order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reviewable_type', 20);
            $table->unsignedBigInteger('reviewable_id');
            $table->unsignedTinyInteger('store_rating');       // the store, or the personal shopper
            $table->text('store_comment')->nullable();
            $table->unsignedTinyInteger('products_rating');
            $table->text('products_comment')->nullable();
            $table->timestamps();

            $table->unique(['reviewable_type', 'reviewable_id']);   // one review per order
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
