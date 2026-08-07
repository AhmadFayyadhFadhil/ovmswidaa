<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vehicles')) {
            // Force reset all vehicles status to Available in live database
            DB::table('vehicles')->update(['status' => 'Available']);
        }
    }

    public function down(): void
    {
    }
};
