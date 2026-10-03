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
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->json('title')->comment('Translatable: {"ar": "...", "en": "..."}');
            $table->json('subtitle')->nullable()->comment('Translatable: {"ar": "...", "en": "..."}');
            $table->json('button_text')->nullable()->comment('Translatable: {"ar": "...", "en": "..."}');
            $table->string('image');
            $table->string('link')->nullable()->comment('URL or in-app deep link opened by the banner button');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
