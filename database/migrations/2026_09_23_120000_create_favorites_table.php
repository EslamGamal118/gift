<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer favorites, polymorphic over stores and products
     * (`favoritable_type` holds the morph alias: store | product).
     */
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('favoritable_type', 20);
            $table->unsignedBigInteger('favoritable_id');
            $table->timestamps();

            // One row per user and item; also serves the "is this a favorite"
            // EXISTS check and the per-user listing (user_id, type prefix).
            $table->unique(['user_id', 'favoritable_type', 'favoritable_id']);
            // Reverse lookups: clean-up when an item is deleted, favorite counts.
            $table->index(['favoritable_type', 'favoritable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};
