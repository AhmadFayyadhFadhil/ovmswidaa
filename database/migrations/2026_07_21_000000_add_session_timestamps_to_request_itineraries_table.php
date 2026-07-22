<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_itineraries', function (Blueprint $table) {
            $table->dateTime('morning_checked_out_at')->nullable()->after('third_party_cost');
            $table->dateTime('morning_checked_in_at')->nullable()->after('morning_checked_out_at');
            $table->string('morning_status')->default('pending')->after('morning_checked_in_at');

            $table->dateTime('afternoon_checked_out_at')->nullable()->after('morning_status');
            $table->dateTime('afternoon_checked_in_at')->nullable()->after('afternoon_checked_out_at');
            $table->string('afternoon_status')->default('pending')->after('afternoon_checked_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('request_itineraries', function (Blueprint $table) {
            $table->dropColumn([
                'morning_checked_out_at',
                'morning_checked_in_at',
                'morning_status',
                'afternoon_checked_out_at',
                'afternoon_checked_in_at',
                'afternoon_status',
            ]);
        });
    }
};
