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
            $table->string('external_driver_name_2')->nullable()->after('external_license_plate');
            $table->string('external_license_plate_2')->nullable()->after('external_driver_name_2');
            $table->string('external_fleet_info_2')->nullable()->after('external_photo_path');
            $table->string('external_photo_path_2')->nullable()->after('external_fleet_info_2');
            
            $table->decimal('external_departure_cost_2', 15, 2)->default(0)->after('external_departure_cost');
            $table->decimal('external_return_cost_2', 15, 2)->default(0)->after('external_return_cost');
            
            $table->string('external_return_driver_name_2')->nullable()->after('external_return_license_plate');
            $table->string('external_return_license_plate_2')->nullable()->after('external_return_driver_name_2');
            $table->string('external_return_fleet_info_2')->nullable()->after('external_return_photo_path');
            $table->string('external_return_photo_path_2')->nullable()->after('external_return_fleet_info_2');
            
            $table->decimal('third_party_cost_2', 15, 2)->default(0)->after('third_party_cost');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn([
                'external_driver_name_2',
                'external_license_plate_2',
                'external_fleet_info_2',
                'external_photo_path_2',
                'external_departure_cost_2',
                'external_return_cost_2',
                'external_return_driver_name_2',
                'external_return_license_plate_2',
                'external_return_fleet_info_2',
                'external_return_photo_path_2',
                'third_party_cost_2',
            ]);
        });
    }
};
