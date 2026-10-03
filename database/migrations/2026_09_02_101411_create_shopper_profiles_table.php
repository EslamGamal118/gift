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
       Schema::create('shopper_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('personal_photo')->nullable();
            $table->string('bio', 500)->nullable();                  // shown when customers pick a shopper
            $table->string('iban');                    
            $table->string('iban_certificate_file')->nullable(); 
            $table->string('national_id_number')->nullable(); 
            $table->string('national_id_file')->nullable();      
             $table->string('driving_license_file')->nullable();       
            $table->string('freelance_license_file')->nullable(); 
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0);         // maintained by the reviews system
            $table->unsignedInteger('rating_count')->default(0);
            $table->boolean('is_available')->default(true);          // online / offline toggle
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected'])->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['status', 'is_available'], 'shopper_profiles_status_available_index');
            $table->index(['latitude', 'longitude'], 'shopper_profiles_geo_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopper_profiles');
    }
};
