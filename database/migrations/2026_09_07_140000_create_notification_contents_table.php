<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The text of a notification, stored as translation keys rather than sentences.
 *
 * Writing the rendered Arabic string into the row -- which is what
 * `provider_notifications.content` and `admin_notifications.content` do today --
 * freezes the notification in whatever language the sender happened to be using
 * at the moment the event fired. Storing `title_key` plus `title_params` defers
 * the rendering to read time, so switching the app language re-renders the whole
 * history instead of only the notifications sent after the switch.
 *
 * One row per *event*, not per recipient: a broadcast to ten thousand clients is
 * one row here and ten thousand pointer rows in `app_notifications`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_contents', function (Blueprint $table) {
            $table->id();

            // e.g. 'notifications.order_updated_title'
            $table->string('title_key');
            $table->string('body_key');

            // e.g. {"order_id": 105} -- the :placeholders the keys interpolate.
            $table->json('title_params')->nullable();
            $table->json('body_params')->nullable();

            // Event category: 'order_status', 'new_offer', 'wallet_update'...
            $table->string('type')->nullable();

            // Deep link payload: {"screen": "order_details", "id": 105}.
            $table->json('data')->nullable();

            // Client, Provider, Admin -- or null when the system raised it.
            $table->nullableMorphs('sender');

            $table->timestamps();

            // The admin console filters the feed by category.
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_contents');
    }
};
