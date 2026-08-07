<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vehicles')) {
            // Ensure INOVA exists
            $inova = DB::table('vehicles')->where('plate_number', 'L 7686 TY')->first();
            if (!$inova) {
                DB::table('vehicles')->insert([
                    'name' => 'INOVA',
                    'plate_number' => 'L 7686 TY',
                    'type' => 'MPV',
                    'capacity' => 7,
                    'status' => 'Available',
                    'odometer' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Ensure XENIA exists
            $xenia = DB::table('vehicles')->where('plate_number', 'B 1751 UY')->first();
            if (!$xenia) {
                DB::table('vehicles')->insert([
                    'name' => 'XENIA',
                    'plate_number' => 'B 1751 UY',
                    'type' => 'MPV',
                    'capacity' => 7,
                    'status' => 'Available',
                    'odometer' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Force all vehicles to Available
            DB::table('vehicles')->update(['status' => 'Available']);
        }
    }

    public function down(): void {}
};
