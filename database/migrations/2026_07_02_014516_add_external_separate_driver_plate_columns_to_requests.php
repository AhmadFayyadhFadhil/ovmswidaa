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
        Schema::table('requests', function (Blueprint $table) {
            $table->string('external_driver_name')->nullable();
            $table->string('external_license_plate')->nullable();
            $table->string('external_return_driver_name')->nullable();
            $table->string('external_return_license_plate')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn([
                'external_driver_name',
                'external_license_plate',
                'external_return_driver_name',
                'external_return_license_plate'
            ]);
        });
    }
};
