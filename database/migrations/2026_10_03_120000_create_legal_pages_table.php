<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legal center pages (privacy policy, terms and conditions), one row per type.
 * `title` and `content` hold one value per locale: content is the page's
 * ordered sections ({"ar": [{key, heading, body}, ...], "en": [...]}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_pages', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40)->unique();   // privacy_policy | terms_and_conditions
            $table->json('title');
            $table->json('content');
            $table->boolean('is_published')->default(true);
            $table->timestamps();                     // updated_at = "last updated" shown in the app
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_pages');
    }
};
