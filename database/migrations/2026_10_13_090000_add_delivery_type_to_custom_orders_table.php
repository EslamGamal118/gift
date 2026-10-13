<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom orders choose their delivery like store orders: instant, or a date +
 * time slot (POST /custom-orders/{id}/confirm). Existing orders were all
 * scheduled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_orders', function (Blueprint $table) {
            $table->enum('delivery_type', ['scheduled', 'instant'])->default('scheduled')->after('delivery_at');
        });
    }

    public function down(): void
    {
        Schema::table('custom_orders', function (Blueprint $table) {
            $table->dropColumn('delivery_type');
        });
    }
};
