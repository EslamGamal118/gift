<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('captain_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('iban');
            $table->string('iban_certificate_file')->nullable();
            $table->string('personal_photo')->nullable();
            $table->foreignId('vehicle_type_id')->nullable()->constrained('vehicle_types')->nullOnDelete();           
            $table->string('vehicle_model')->nullable();
            $table->string('vehicle_image_file')->nullable();
            $table->string('plate_number_file')->nullable();
            $table->string('plate_number', 20)->nullable();      
            $table->string('license_file')->nullable();         
            $table->string('plate_image_file')->nullable(); 
            $table->string('commercial_register_file')->nullable(); 
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('address')->nullable();
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected'])->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('captain_profiles');
    }
};
