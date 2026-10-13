<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin dashboard: per-store commission, moderation notes, soft deletes,
 * and the payout ledger (money transferred to stores and the orders it settles).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_profiles', function (Blueprint $table) {
            // Platform commission, % of the order subtotal (products + add-ons, before discount and VAT)
            $table->decimal('commission_rate', 5, 2)->default(10)->after('preparation_time');
            // Suspension = the owner account is blocked (users.status); why and when is kept here
            $table->text('suspension_reason')->nullable()->after('rejection_reason');
            $table->timestamp('suspended_at')->nullable()->after('suspension_reason');
            $table->text('admin_notes')->nullable()->after('suspended_at');   // internal, never shown to the store
            $table->softDeletes();
        });

        Schema::create('store_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_profile_id')->constrained('store_profiles')->restrictOnDelete();
            $table->decimal('amount', 12, 2);                       // sum of the settled orders' store share
            $table->string('currency', 3)->default('SAR');
            $table->unsignedInteger('orders_count')->default(0);
            $table->string('method', 30);                           // bank_transfer | wallet | cash | other
            $table->string('reference', 100)->nullable();           // bank transfer / wallet reference
            $table->string('receipt_path')->nullable();             // transfer receipt (public disk)
            $table->text('notes')->nullable();
            $table->dateTime('transferred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();   // admin who logged it
            $table->timestamps();

            $table->index(['store_profile_id', 'transferred_at']);
        });

        // The orders a payout settles, with the amounts frozen at payout time
        // (a later commission change never rewrites past payouts)
        Schema::create('store_payout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_payout_id')->constrained('store_payouts')->cascadeOnDelete();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();   // an order is paid out once
            $table->decimal('subtotal', 10, 2);
            $table->decimal('commission_rate', 5, 2);
            $table->decimal('commission_amount', 10, 2);
            $table->decimal('store_amount', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_payout_items');
        Schema::dropIfExists('store_payouts');

        Schema::table('store_profiles', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['commission_rate', 'suspension_reason', 'suspended_at', 'admin_notes']);
        });
    }
};
