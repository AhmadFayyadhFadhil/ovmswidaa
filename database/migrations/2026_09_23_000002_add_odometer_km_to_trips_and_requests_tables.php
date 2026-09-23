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
        Schema::table('operational_trips', function (Blueprint $table) {
            if (!Schema::hasColumn('operational_trips', 'start_km')) {
                $table->unsignedBigInteger('start_km')->nullable()->after('security_checkin_notes');
            }
            if (!Schema::hasColumn('operational_trips', 'end_km')) {
                $table->unsignedBigInteger('end_km')->nullable()->after('start_km');
            }
            if (!Schema::hasColumn('operational_trips', 'total_km')) {
                $table->unsignedBigInteger('total_km')->nullable()->after('end_km');
            }
        });

        Schema::table('request_itineraries', function (Blueprint $table) {
            if (!Schema::hasColumn('request_itineraries', 'start_km')) {
                $table->unsignedBigInteger('start_km')->nullable();
            }
            if (!Schema::hasColumn('request_itineraries', 'end_km')) {
                $table->unsignedBigInteger('end_km')->nullable();
            }
            if (!Schema::hasColumn('request_itineraries', 'total_km')) {
                $table->unsignedBigInteger('total_km')->nullable();
            }
        });

        Schema::table('requests', function (Blueprint $table) {
            if (!Schema::hasColumn('requests', 'start_km')) {
                $table->unsignedBigInteger('start_km')->nullable();
            }
            if (!Schema::hasColumn('requests', 'end_km')) {
                $table->unsignedBigInteger('end_km')->nullable();
            }
            if (!Schema::hasColumn('requests', 'total_km')) {
                $table->unsignedBigInteger('total_km')->nullable();
            }
        });

        Schema::table('vehicles', function (Blueprint $table) {
            if (!Schema::hasColumn('vehicles', 'odometer')) {
                $table->unsignedBigInteger('odometer')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('operational_trips', function (Blueprint $table) {
            $cols = array_filter(['start_km', 'end_km', 'total_km'], fn($c) => Schema::hasColumn('operational_trips', $c));
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('request_itineraries', function (Blueprint $table) {
            $cols = array_filter(['start_km', 'end_km', 'total_km'], fn($c) => Schema::hasColumn('request_itineraries', $c));
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('requests', function (Blueprint $table) {
            $cols = array_filter(['start_km', 'end_km', 'total_km'], fn($c) => Schema::hasColumn('requests', $c));
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
