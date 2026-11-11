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
            // Mode Perjalanan: standby (nunggu seharian) vs split_drop_pickup (antar-jemput terpisah)
            if (!Schema::hasColumn('requests', 'trip_mode')) {
                $table->string('trip_mode')->default('standby')->after('priority');
            }

            // Sesi 1: Departure / Antar tracking
            if (!Schema::hasColumn('requests', 'departure_start_km')) {
                $table->decimal('departure_start_km', 10, 2)->nullable()->after('end_km');
                $table->decimal('departure_end_km', 10, 2)->nullable()->after('departure_start_km');
                $table->dateTime('departure_checked_out_at')->nullable()->after('departure_end_km');
                $table->dateTime('departure_checked_in_at')->nullable()->after('departure_checked_out_at');
                $table->string('departure_checkout_by')->nullable()->after('departure_checked_in_at');
                $table->string('departure_checkin_by')->nullable()->after('departure_checkout_by');
                $table->text('departure_checkout_notes')->nullable()->after('departure_checkin_by');
                $table->text('departure_checkin_notes')->nullable()->after('departure_checkout_notes');
            }

            // Sesi 2: Return / Jemput planning & execution
            if (!Schema::hasColumn('requests', 'return_departure_time')) {
                $table->dateTime('return_departure_time')->nullable()->after('departure_checkin_notes');
                $table->foreignId('return_driver_id')->nullable()->after('return_departure_time')->constrained('users')->nullOnDelete();
                $table->foreignId('return_vehicle_id')->nullable()->after('return_driver_id')->constrained('vehicles')->nullOnDelete();
                $table->text('return_notes')->nullable()->after('return_vehicle_id');
                $table->decimal('return_start_km', 10, 2)->nullable()->after('return_notes');
                $table->decimal('return_end_km', 10, 2)->nullable()->after('return_start_km');
                $table->dateTime('return_checked_out_at')->nullable()->after('return_end_km');
                $table->dateTime('return_checked_in_at')->nullable()->after('return_checked_out_at');
                $table->string('return_checkout_by')->nullable()->after('return_checked_in_at');
                $table->string('return_checkin_by')->nullable()->after('return_checkout_by');
                $table->text('return_checkout_notes')->nullable()->after('return_checkin_by');
                $table->text('return_checkin_notes')->nullable()->after('return_checkout_notes');
            }

            // Re-assignment history tracking for Return Trip
            if (!Schema::hasColumn('requests', 'return_reassigned_at')) {
                $table->dateTime('return_reassigned_at')->nullable()->after('return_checkin_notes');
                $table->foreignId('return_reassigned_by')->nullable()->after('return_reassigned_at')->constrained('users')->nullOnDelete();
                $table->text('return_reassign_notes')->nullable()->after('return_reassigned_by');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $dropColumns = [
                'trip_mode',
                'departure_start_km',
                'departure_end_km',
                'departure_checked_out_at',
                'departure_checked_in_at',
                'departure_checkout_by',
                'departure_checkin_by',
                'departure_checkout_notes',
                'departure_checkin_notes',
                'return_departure_time',
                'return_driver_id',
                'return_vehicle_id',
                'return_notes',
                'return_start_km',
                'return_end_km',
                'return_checked_out_at',
                'return_checked_in_at',
                'return_checkout_by',
                'return_checkin_by',
                'return_checkout_notes',
                'return_checkin_notes',
                'return_reassigned_at',
                'return_reassigned_by',
                'return_reassign_notes',
            ];

            foreach ($dropColumns as $column) {
                if (Schema::hasColumn('requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
