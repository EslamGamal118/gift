<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One delivery of a notification_content to one recipient.
 *
 * Holds no text at all -- only who received it and whether they have read it.
 * Everything readable lives on the content row, which is what lets a single
 * broadcast be stored once and pointed at from every inbox.
 *
 * cascadeOnDelete is deliberate: a content row with no deliveries is unreachable
 * text, and a delivery whose content is gone renders as an empty notification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('notification_content_id')
                ->constrained('notification_contents')
                ->cascadeOnDelete();

            // Recipient: Client, Provider or Admin. Plain columns rather than
            // morphs(): its (type, id) index would duplicate the inbox index below.
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');

            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // The two hot queries are the unread badge count and the inbox list,
            // and both start from one recipient. Carrying is_read and created_at
            // after (type, id) answers the badge from the index and hands the
            // list back already ordered; its (type, id) prefix also serves every
            // plain per-recipient lookup.
            $table->index(
                ['notifiable_type', 'notifiable_id', 'is_read', 'created_at'],
                'app_notifications_inbox_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
