<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A paid custom order is no longer `completed` at once: it is `paid`, then
     * follows the delivery company's (Alshrouq) statuses until delivered
     * (`completed`) or cancelled.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE custom_orders MODIFY status ENUM(
            'draft', 'pending', 'accepted', 'in_progress', 'waiting_for_alternative', 'waiting_for_payment',
            'paid', 'order_created', 'pending_driver_acceptance', 'driver_accepted', 'pending_order_preparation',
            'arrived_to_pickup', 'order_picked_up', 'arrived_to_dropoff', 'completed',
            'cancellation_processing', 'cancelled'
        ) NOT NULL DEFAULT 'draft'");
        // Cancelled by the delivery company: not refunded automatically (CustomOrderObserver)
        DB::statement("ALTER TABLE custom_orders MODIFY cancelled_by ENUM('customer', 'shopper', 'system', 'delivery') NULL");

        Schema::table('custom_orders', function (Blueprint $table) {
            $table->string('delivery_reference', 64)->nullable()->after('completed_at');    // the delivery company's order id
            $table->string('delivery_status', 64)->nullable()->after('delivery_reference'); // its last raw status
            $table->timestamp('delivery_updated_at')->nullable()->after('delivery_status');
        });
    }

    public function down(): void
    {
        DB::table('custom_orders')->where('status', 'cancellation_processing')->update(['status' => 'cancelled']);
        DB::table('custom_orders')->where('cancelled_by', 'delivery')->update(['cancelled_by' => 'system']);
        DB::table('custom_orders')
            ->whereIn('status', ['paid', 'order_created', 'pending_driver_acceptance', 'driver_accepted', 'pending_order_preparation', 'arrived_to_pickup', 'order_picked_up', 'arrived_to_dropoff'])
            ->update(['status' => 'completed']);

        DB::statement("ALTER TABLE custom_orders MODIFY status ENUM(
            'draft', 'pending', 'accepted', 'in_progress', 'waiting_for_alternative', 'waiting_for_payment', 'completed', 'cancelled'
        ) NOT NULL DEFAULT 'draft'");
        DB::statement("ALTER TABLE custom_orders MODIFY cancelled_by ENUM('customer', 'shopper', 'system') NULL");

        Schema::table('custom_orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_reference', 'delivery_status', 'delivery_updated_at']);
        });
    }
};
