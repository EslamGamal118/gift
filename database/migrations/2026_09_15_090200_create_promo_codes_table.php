<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('description')->nullable();
            $table->enum('type', ['percentage', 'fixed']);
            $table->decimal('value', 10, 2);                          // 10 => 10% or 10 SAR
            $table->decimal('min_order_amount', 10, 2)->nullable();   // applies to the subtotal
            $table->decimal('max_discount', 10, 2)->nullable();       // cap for percentage codes
            // Null = usable in any store; otherwise only for orders from this merchant account.
            $table->foreignId('store_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('usage_limit')->nullable();       // total redemptions
            $table->unsignedInteger('usage_limit_per_user')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};
