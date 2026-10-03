<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 5)->default('966');
            $table->string('phone', 20);
            $table->string('name', 100)->nullable();
            $table->string('otp_code', 6);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['phone', 'otp_code'], 'phone_verifications_phone_code_index');
            $table->index('expires_at', 'phone_verifications_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_verifications');
    }
};
