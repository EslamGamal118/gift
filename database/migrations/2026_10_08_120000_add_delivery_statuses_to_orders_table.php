<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store orders' own statuses (see App\Support\OrderStateMachine).
     */
    protected const ORDER_STATUSES = [
        'pending_payment', 'pending', 'accepted', 'processing', 'ready', 'out_for_delivery', 'delivered', 'cancelled',
    ];

    /**
     * Statuses added for the custom orders workflow: the delivery company's
     * (Alshrouq) statuses, as on custom_orders.
     */
    protected const ADDED_STATUSES = [
        'draft', 'in_progress', 'waiting_for_alternative', 'waiting_for_payment', 'paid',
        'order_created', 'pending_driver_acceptance', 'driver_accepted', 'pending_order_preparation',
        'arrived_to_pickup', 'order_picked_up', 'arrived_to_dropoff', 'completed', 'cancellation_processing',
    ];

    protected const ORDER_CANCELLED_BY = ['store', 'customer', 'captain', 'system'];

    protected const ADDED_CANCELLED_BY = ['shopper', 'delivery'];

    /**
     * Each added status => the store-order status it falls back to on rollback.
     */
    protected const ROLLBACK_STATUSES = [
        'draft' => 'pending_payment',
        'waiting_for_payment' => 'pending_payment',
        'paid' => 'pending',
        'in_progress' => 'processing',
        'waiting_for_alternative' => 'processing',
        'order_created' => 'ready',
        'pending_driver_acceptance' => 'ready',
        'driver_accepted' => 'ready',
        'pending_order_preparation' => 'ready',
        'arrived_to_pickup' => 'ready',
        'order_picked_up' => 'out_for_delivery',
        'arrived_to_dropoff' => 'out_for_delivery',
        'completed' => 'delivered',
        'cancellation_processing' => 'cancelled',
    ];

    /**
     * Store orders can now follow the delivery company's (Alshrouq) statuses,
     * like custom orders. Every existing value is kept: the store-order
     * statuses (`pending_payment`, `processing`, `ready`, `out_for_delivery`,
     * `delivered`...) and cancellers (`store`, `captain`) are in use.
     */
    public function up(): void
    {
        $this->setEnum('status', [...self::ORDER_STATUSES, ...self::ADDED_STATUSES], "NOT NULL DEFAULT 'pending_payment'");
        // Cancelled by the delivery company: not refunded automatically, as on custom orders
        $this->setEnum('cancelled_by', [...self::ORDER_CANCELLED_BY, ...self::ADDED_CANCELLED_BY], 'NULL');

        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_reference', 64)->nullable()->after('delivered_at');    // the delivery company's order id
            $table->string('delivery_status', 64)->nullable()->after('delivery_reference'); // its last raw status
            $table->timestamp('delivery_updated_at')->nullable()->after('delivery_status');

            $table->index('delivery_reference');
        });
    }

    public function down(): void
    {
        foreach (self::ROLLBACK_STATUSES as $added => $original) {
            DB::table('orders')->where('status', $added)->update(['status' => $original]);
        }
        DB::table('orders')->whereIn('cancelled_by', self::ADDED_CANCELLED_BY)->update(['cancelled_by' => 'system']);

        $this->setEnum('status', self::ORDER_STATUSES, "NOT NULL DEFAULT 'pending_payment'");
        $this->setEnum('cancelled_by', self::ORDER_CANCELLED_BY, 'NULL');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['delivery_reference']);
            $table->dropColumn(['delivery_reference', 'delivery_status', 'delivery_updated_at']);
        });
    }

    /**
     * @param  list<string>  $values
     */
    protected function setEnum(string $column, array $values, string $definition): void
    {
        $list = implode(', ', array_map(fn (string $value) => "'{$value}'", $values));

        DB::statement("ALTER TABLE orders MODIFY {$column} ENUM({$list}) {$definition}");
    }
};
