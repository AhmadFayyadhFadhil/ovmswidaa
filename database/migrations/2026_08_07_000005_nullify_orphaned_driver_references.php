<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Safely nullify orphaned driver_id references pointing to non-existent users.
     */
    public function up(): void
    {
        $validUserIds = DB::table('users')->pluck('id')->toArray();

        if (Schema::hasTable('requests')) {
            DB::table('requests')
                ->whereNotNull('driver_id')
                ->whereNotIn('driver_id', $validUserIds)
                ->update(['driver_id' => null]);
        }

        if (Schema::hasTable('request_itineraries')) {
            DB::table('request_itineraries')
                ->whereNotNull('driver_id')
                ->whereNotIn('driver_id', $validUserIds)
                ->update(['driver_id' => null]);
        }

        if (Schema::hasTable('operational_trips')) {
            DB::table('operational_trips')
                ->whereNotNull('driver_id')
                ->whereNotIn('driver_id', $validUserIds)
                ->update(['driver_id' => null]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Non-destructive cleanup migration; no rollback needed.
    }
};
