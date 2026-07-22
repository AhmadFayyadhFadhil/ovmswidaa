<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_itineraries', function (Blueprint $table) {
            $table->string('morning_checkout_by')->nullable()->after('morning_status');
            $table->string('morning_checkin_by')->nullable()->after('morning_checkout_by');
            $table->text('morning_checkout_notes')->nullable()->after('morning_checkin_by');
            $table->text('morning_checkin_notes')->nullable()->after('morning_checkout_notes');

            $table->string('afternoon_checkout_by')->nullable()->after('afternoon_status');
            $table->string('afternoon_checkin_by')->nullable()->after('afternoon_checkout_by');
            $table->text('afternoon_checkout_notes')->nullable()->after('afternoon_checkin_by');
            $table->text('afternoon_checkin_notes')->nullable()->after('afternoon_checkout_notes');
        });
    }

    public function down(): void
    {
        Schema::table('request_itineraries', function (Blueprint $table) {
            $table->dropColumn([
                'morning_checkout_by',
                'morning_checkin_by',
                'morning_checkout_notes',
                'morning_checkin_notes',
                'afternoon_checkout_by',
                'afternoon_checkin_by',
                'afternoon_checkout_notes',
                'afternoon_checkin_notes',
            ]);
        });
    }
};
