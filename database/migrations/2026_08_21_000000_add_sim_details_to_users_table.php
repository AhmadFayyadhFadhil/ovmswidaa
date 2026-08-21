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
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'sim_number')) {
                $table->string('sim_number')->nullable()->after('nik');
            }
            if (!Schema::hasColumn('users', 'sim_type')) {
                $table->string('sim_type')->nullable()->default('SIM A')->after('sim_number');
            }
            if (!Schema::hasColumn('users', 'sim_expiry_date')) {
                $table->date('sim_expiry_date')->nullable()->after('sim_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columnsToDrop = [];
            if (Schema::hasColumn('users', 'sim_number')) {
                $columnsToDrop[] = 'sim_number';
            }
            if (Schema::hasColumn('users', 'sim_type')) {
                $columnsToDrop[] = 'sim_type';
            }
            if (Schema::hasColumn('users', 'sim_expiry_date')) {
                $columnsToDrop[] = 'sim_expiry_date';
            }
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
