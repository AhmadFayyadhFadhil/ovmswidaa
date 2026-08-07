<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('requests')) {
            Schema::disableForeignKeyConstraints();

            DB::table('request_itineraries')->truncate();
            DB::table('operational_trips')->truncate();
            DB::table('assignments')->truncate();
            DB::table('request_approvals')->truncate();
            DB::table('passengers')->truncate();
            DB::table('requests')->truncate();

            if (Schema::hasTable('notifications')) {
                DB::table('notifications')->truncate();
            }
            if (Schema::hasTable('audit_logs')) {
                DB::table('audit_logs')->truncate();
            }

            if (Schema::hasTable('users')) {
                DB::table('users')->update(['availability_status' => 'available']);
            }
            if (Schema::hasTable('vehicles')) {
                DB::table('vehicles')->update(['status' => 'Available']);
            }

            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
    }
};
