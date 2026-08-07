<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('passengers') && Schema::hasTable('requests')) {
            // Delete extra test passengers Hanafi & Gita from REQ-33
            DB::table('passengers')
                ->where('request_id', 33)
                ->whereIn('name', ['Hanafi', 'Gita Thessa Lonika Putri'])
                ->delete();

            // Set Fayyadh as sole PIC for REQ-33
            DB::table('passengers')
                ->where('request_id', 33)
                ->update(['is_pic' => true]);

            // Update passenger count for REQ-33
            DB::table('requests')
                ->where('id', 33)
                ->update(['passenger_count' => 1]);
        }
    }

    public function down(): void
    {
        // No revert needed
    }
};
