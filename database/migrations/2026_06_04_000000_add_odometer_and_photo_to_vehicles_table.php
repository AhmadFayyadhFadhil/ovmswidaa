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
        Schema::table('vehicles', function (Blueprint $table) {
            if (!Schema::hasColumn('vehicles', 'odometer')) {
                $table->unsignedInteger('odometer')->nullable()->after('capacity');
            }
            if (!Schema::hasColumn('vehicles', 'photo')) {
                $table->string('photo')->nullable()->after('odometer');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            if (Schema::hasColumn('vehicles', 'photo')) {
                $table->dropColumn('photo');
            }
            if (Schema::hasColumn('vehicles', 'odometer')) {
                $table->dropColumn('odometer');
            }
        });
    }
};
