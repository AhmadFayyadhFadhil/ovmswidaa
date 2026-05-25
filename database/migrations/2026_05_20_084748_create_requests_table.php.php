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
        Schema::create('requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->onDelete('set null');
            $table->string('purpose');
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->enum('status', ['Pending', 'Approved', 'Rejected', 'Completed', 'Cancelled'])->default('Pending');
            $table->foreignId('approver_id')->nullable()->constrained('users')->onDelete('set null');
            $table->dateTime('approval_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('requests');
    }
};
