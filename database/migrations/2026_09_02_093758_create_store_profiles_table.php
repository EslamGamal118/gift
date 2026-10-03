<?php

use App\Support\SearchText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('store_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('store_name')->nullable();
            $table->foreignId('category_id')
            ->nullable()
             ->constrained('categories')
             ->nullOnDelete();
            $table->string('phone')->nullable();
            $table->string('email')->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('iban');
            $table->string('iban_certificate_file');
            $table->string('logo')->nullable();
            $table->string('cover_image')->nullable();
            $table->string('commercial_register_file')->nullable();
            $table->json('working_hours')->nullable();
            $table->decimal('delivery_fee', 8, 2)->nullable();
            $table->unsignedSmallInteger('preparation_time')->default(20);
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->boolean('is_featured')->default(false);          // curated home section

            $table->enum('status', ['draft', 'pending', 'approved', 'rejected'])->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            // Normalized search text (App\Support\SearchText) kept in sync by MySQL itself
            $table->text('search_text')->storedAs(SearchText::sql("CONCAT_WS(' ', store_name, description)"));

            $table->index(['status', 'category_id']);
            $table->index(['status', 'is_featured'], 'store_profiles_status_featured_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_profiles');
    }
};
