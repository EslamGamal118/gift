<?php

use App\Support\SearchText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->integer('stock_quantity')->default(0);
            $table->date('expiry_date')->nullable();
            // Online gifts: days a paid gift stays redeemable (null = config gifts.validity_days)
            $table->unsignedSmallInteger('gift_validity_days')->nullable();
            $table->string('image')->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedSmallInteger('preparation_time')->default(20);
            $table->unsignedInteger('rating_count')->default(0);
            $table->boolean('is_featured')->default(false);          // curated home section
            $table->timestamps();

            // Normalized search text (App\Support\SearchText) kept in sync by MySQL itself,
            // so keyword search compares like with like without per-row PHP
            $table->text('search_text')->storedAs(SearchText::sql("CONCAT_WS(' ', name, description)"));

            $table->index(['is_featured', 'category_id'], 'products_featured_category_index');
            // "Stores offering products in category X"; its category_id prefix
            // also backs the category foreign key (no separate index needed)
            $table->index(['category_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
