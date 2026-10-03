<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer ratings of a store. `store_profiles.rating_avg` / `rating_count`
 * are denormalised from this table (see StoreReview::recalculateFor()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_profile_id')->constrained('store_profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // One review per delivered order; null for reviews not tied to an order (imports, seeds)
            $table->foreignId('order_id')->nullable()->unique()->constrained('orders')->nullOnDelete();
            $table->unsignedTinyInteger('rating');            // 1..5
            $table->text('comment')->nullable();
            $table->boolean('is_visible')->default(true);     // moderation switch
            $table->timestamps();

            $table->index(['store_profile_id', 'is_visible', 'created_at'], 'store_reviews_listing_index');
            $table->index(['user_id', 'store_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_reviews');
    }
};
