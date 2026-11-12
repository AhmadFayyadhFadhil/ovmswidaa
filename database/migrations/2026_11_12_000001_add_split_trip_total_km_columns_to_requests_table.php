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
            if (!Schema::hasColumn('requests', 'departure_total_km')) {
                $table->decimal('departure_total_km', 10, 2)->nullable()->after('departure_end_km');
            }
            if (!Schema::hasColumn('requests', 'return_total_km')) {
                $table->decimal('return_total_km', 10, 2)->nullable()->after('return_end_km');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (Schema::hasColumn('requests', 'departure_total_km')) {
                $table->dropColumn('departure_total_km');
            }
            if (Schema::hasColumn('requests', 'return_total_km')) {
                $table->dropColumn('return_total_km');
            }
        });
    }
};
