<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('avatar')->nullable();  
            $table->string('locale', 5)->nullable();       
            $table->enum('user_type', ['customer', 'store', 'captain', 'shopper', 'admin'])->default('customer');
            $table->enum('status', ['active', 'blocked'])->default('active');
            $table->timestamp('phone_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            // Also serves lookups by phone alone (leftmost column), so no separate phone index
            $table->unique(['phone', 'user_type'], 'users_phone_user_type_unique');
            $table->unique(['email', 'user_type'], 'users_email_user_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};