<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('requests') && !Schema::hasColumn('requests', 'additional_passengers')) {
            Schema::table('requests', function (Blueprint $table) {
                $table->text('additional_passengers')->nullable()->after('security_checkout_notes');
            });
        }

        if (Schema::hasTable('operational_trips') && !Schema::hasColumn('operational_trips', 'additional_passengers')) {
            Schema::table('operational_trips', function (Blueprint $table) {
                $table->text('additional_passengers')->nullable()->after('security_checkout_notes');
            });
        }

        if (Schema::hasTable('request_itineraries') && !Schema::hasColumn('request_itineraries', 'additional_passengers')) {
            Schema::table('request_itineraries', function (Blueprint $table) {
                $table->text('additional_passengers')->nullable()->after('morning_checkout_notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('requests') && Schema::hasColumn('requests', 'additional_passengers')) {
            Schema::table('requests', function (Blueprint $table) {
                $table->dropColumn('additional_passengers');
            });
        }

        if (Schema::hasTable('operational_trips') && Schema::hasColumn('operational_trips', 'additional_passengers')) {
            Schema::table('operational_trips', function (Blueprint $table) {
                $table->dropColumn('additional_passengers');
            });
        }

        if (Schema::hasTable('request_itineraries') && Schema::hasColumn('request_itineraries', 'additional_passengers')) {
            Schema::table('request_itineraries', function (Blueprint $table) {
                $table->dropColumn('additional_passengers');
            });
        }
    }
};
