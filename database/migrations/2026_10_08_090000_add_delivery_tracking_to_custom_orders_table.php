<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracking of the delivery company's (Alshrouq) order: when it was created,
     * its create response, the latest driver details and the last dispatch error.
     */
    public function up(): void
    {
        Schema::table('custom_orders', function (Blueprint $table) {
            $table->timestamp('delivery_dispatched_at')->nullable()->after('delivery_updated_at');
            $table->json('delivery_data')->nullable()->after('delivery_dispatched_at');    // create response (fees, distance...)
            $table->json('delivery_driver')->nullable()->after('delivery_data');           // name, phone, tracking_url, location
            $table->string('delivery_error')->nullable()->after('delivery_driver');        // last failed dispatch

            $table->index('delivery_reference');
        });
    }

    public function down(): void
    {
        Schema::table('custom_orders', function (Blueprint $table) {
            $table->dropIndex(['delivery_reference']);
            $table->dropColumn(['delivery_dispatched_at', 'delivery_data', 'delivery_driver', 'delivery_error']);
        });
    }
};
