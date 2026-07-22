<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_itineraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $table->date('date');
            
            // Morning schedule
            $table->string('morning_time')->nullable();
            $table->string('morning_destination')->nullable();
            
            // Afternoon schedule
            $table->string('afternoon_time')->nullable();
            $table->string('afternoon_destination')->nullable();

            $table->text('passengers_notes')->nullable();
            
            // Internal fleet assignment
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            
            // External fleet assignment
            $table->boolean('is_external')->default(false);
            $table->string('external_driver_name')->nullable();
            $table->string('external_license_plate')->nullable();
            $table->text('external_fleet_info')->nullable();
            $table->decimal('third_party_cost', 12, 2)->default(0);

            // Security check-in/out per daily segment
            $table->dateTime('security_checked_out_at')->nullable();
            $table->dateTime('security_checked_in_at')->nullable();
            $table->string('status')->default('pending'); // pending, assigned, on_going, completed

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_itineraries');
    }
};
