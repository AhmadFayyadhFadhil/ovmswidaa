<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Enums\RequestStatus;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Perbaiki status request yang belum scan checkout gerbang tapi tersimpan sebagai on_going
        DB::table('requests')
            ->where('status', 'on_going')
            ->whereNull('security_checked_out_at')
            ->whereNull('departure_checked_out_at')
            ->update([
                'status' => 'driver_assigned',
                'updated_at' => now(),
            ]);

        // 2. Perbaiki split trip yang Sesi 1 sudah kembali ke pabrik (departure_checked_in_at ada, belum return_checked_out_at)
        DB::table('requests')
            ->where('trip_mode', 'split_drop_pickup')
            ->whereNotNull('departure_checked_in_at')
            ->whereNull('return_checked_out_at')
            ->where('status', 'on_going')
            ->update([
                'status' => 'driver_assigned',
                'updated_at' => now(),
            ]);

        // 3. Rekonsiliasi status armada kendaraan
        // Dapatkan semua kendaraan yang BENAR-BENAR sedang berada di luar pabrik (checkout tanpa checkin dan status on_going)
        $activeVehicles1 = DB::table('requests')
            ->where('status', 'on_going')
            ->whereNotNull('departure_checked_out_at')
            ->whereNull('departure_checked_in_at')
            ->whereNotNull('vehicle_id')
            ->pluck('vehicle_id')
            ->toArray();

        $activeVehicles2 = DB::table('requests')
            ->where('status', 'on_going')
            ->whereNotNull('return_checked_out_at')
            ->whereNull('return_checked_in_at')
            ->whereNotNull('return_vehicle_id')
            ->pluck('return_vehicle_id')
            ->toArray();

        $activeVehicles3 = DB::table('requests')
            ->where('status', 'on_going')
            ->where(function ($q) {
                $q->whereNull('trip_mode')->orWhere('trip_mode', '!=', 'split_drop_pickup');
            })
            ->whereNotNull('security_checked_out_at')
            ->whereNull('security_checked_in_at')
            ->whereNotNull('vehicle_id')
            ->pluck('vehicle_id')
            ->toArray();

        $activeVehicles4 = DB::table('operational_trips')
            ->where('status', 'on_going')
            ->whereNotNull('security_checked_out_at')
            ->whereNull('security_checked_in_at')
            ->whereNotNull('vehicle_id')
            ->pluck('vehicle_id')
            ->toArray();

        $genuinelyActiveVehicleIds = array_unique(array_filter(array_merge(
            $activeVehicles1,
            $activeVehicles2,
            $activeVehicles3,
            $activeVehicles4
        )));

        // Pulihkan semua kendaraan yang tidak sedang aktif di luar pabrik dan tidak sedang servis/afkir ke status 'Available'
        $queryToRestore = DB::table('vehicles')
            ->whereNotIn('status', [
                'Available', 'available', 'AVAILABLE', 'Tersedia',
                'Maintenance', 'maintenance', 'MAINTENANCE', 'Servis', 'Perbaikan',
                'Retired', 'retired', 'RETIRED', 'Decommissioned', 'decommissioned', 'Inactive'
            ]);

        if (!empty($genuinelyActiveVehicleIds)) {
            $queryToRestore->whereNotIn('id', $genuinelyActiveVehicleIds);
        }

        $queryToRestore->update([
            'status' => 'Available',
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse required for status reconciliation
    }
};
