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
            $table->dateTime('security_checked_out_at')->nullable()->after('status');
            $table->dateTime('security_checked_in_at')->nullable()->after('security_checked_out_at');
            $table->string('security_checkout_by')->nullable()->after('security_checked_in_at');
            $table->string('security_checkin_by')->nullable()->after('security_checkout_by');
            $table->text('security_checkout_notes')->nullable()->after('security_checkin_by');
            $table->text('security_checkin_notes')->nullable()->after('security_checkout_notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('operational_trips', function (Blueprint $table) {
            $table->dropColumn([
                'security_checked_out_at',
                'security_checked_in_at',
                'security_checkout_by',
                'security_checkin_by',
                'security_checkout_notes',
                'security_checkin_notes',
            ]);
        });
    }
};
