<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin dashboard for personal shoppers: coverage city, commission,
 * moderation notes, soft deletes, review moderation and the payout ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopper_profiles', function (Blueprint $table) {
            // City key from config/cities.php, for display and admin filtering
            // (customers are still matched to shoppers by distance)
            $table->string('city', 50)->nullable()->after('address');
            // Platform commission, % of the shopper's fee (the items cost is reimbursed in full)
            $table->decimal('commission_rate', 5, 2)->default(10)->after('is_available');
            // Suspension = the owner account is blocked (users.status); why and when is kept here
            $table->text('suspension_reason')->nullable()->after('rejection_reason');
            $table->timestamp('suspended_at')->nullable()->after('suspension_reason');
            $table->text('admin_notes')->nullable()->after('suspended_at');   // internal, never shown to the shopper
            $table->softDeletes();

            $table->index('city');
        });

        // Moderation of shopper reviews (custom orders). Store order reviews are
        // moderated on their store_reviews copy (store_reviews.is_visible).
        Schema::table('reviews', function (Blueprint $table) {
            $table->boolean('is_visible')->default(true)->after('products_comment');
        });

        Schema::create('shopper_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopper_profile_id')->constrained('shopper_profiles')->restrictOnDelete();
            $table->decimal('amount', 12, 2);                       // sum of the settled orders' shopper amount
            $table->string('currency', 3)->default('SAR');
            $table->unsignedInteger('orders_count')->default(0);
            $table->string('method', 30);                           // bank_transfer | wallet | cash | other
            $table->string('reference', 100)->nullable();
            $table->string('receipt_path')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('transferred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shopper_profile_id', 'transferred_at']);
        });

        // The custom orders a payout settles, with the amounts frozen at payout time
        Schema::create('shopper_payout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopper_payout_id')->constrained('shopper_payouts')->cascadeOnDelete();
            $table->foreignId('custom_order_id')->unique()->constrained('custom_orders')->cascadeOnDelete();   // paid out once
            $table->decimal('items_cost', 10, 2);                   // reimbursed in full
            $table->decimal('shopper_fee', 10, 2);
            $table->decimal('commission_rate', 5, 2);
            $table->decimal('commission_amount', 10, 2);            // on the fee only
            $table->decimal('shopper_amount', 10, 2);               // items_cost + shopper_fee - commission_amount
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopper_payout_items');
        Schema::dropIfExists('shopper_payouts');

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('is_visible');
        });

        Schema::table('shopper_profiles', function (Blueprint $table) {
            $table->dropIndex(['city']);
            $table->dropSoftDeletes();
            $table->dropColumn(['city', 'commission_rate', 'suspension_reason', 'suspended_at', 'admin_notes']);
        });
    }
};
