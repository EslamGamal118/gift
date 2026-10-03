<?php

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
        Schema::create('store_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_profile_id')->constrained('store_profiles')->cascadeOnDelete();
            $table->string('name'); 
            $table->text('address')->nullable();
            $table->string('city', 50)->nullable()
                ->comment('City key from config/cities.php (e.g. riyadh)');
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_main')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Guests without GPS are served by city
            $table->index('city');
            // Bounding-box pre-filter of the distance query (index scan instead of a distance per row)
            $table->index(['is_active', 'latitude', 'longitude'], 'store_branches_geo_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_branches');
    }
};
