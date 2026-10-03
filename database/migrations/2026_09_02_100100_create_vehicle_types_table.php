<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * يتم حذف الجدول إن كان موجودًا ثم إنشاؤه من جديد.
     * الحقل `name` يُخزَّن كـ JSON لدعم اللغتين العربية والإنجليزية:
     * {"ar": "دراجة نارية", "en": "Motorcycle"}
     */
    public function up(): void
    {

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('vehicle_types');
        Schema::enableForeignKeyConstraints();

        Schema::create('vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->json('name')->comment('Translatable: {"ar": "...", "en": "..."}');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('vehicle_types');
        Schema::enableForeignKeyConstraints();
    }
};
