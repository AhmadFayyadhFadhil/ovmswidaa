<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'idx_requests_status_created');
            $table->index(['department_id', 'status'], 'idx_requests_dept_status');
            $table->index(['driver_id', 'status'], 'idx_requests_driver_status');
        });

        Schema::table('request_itineraries', function (Blueprint $table) {
            $table->index(['request_id', 'date'], 'idx_itineraries_req_date');
            $table->index(['driver_id', 'date'], 'idx_itineraries_driver_date');
        });

        Schema::table('operational_trips', function (Blueprint $table) {
            $table->index(['request_id', 'status'], 'idx_op_trips_req_status');
            $table->index(['driver_id', 'status'], 'idx_op_trips_driver_status');
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->index(['request_id', 'status'], 'idx_assignments_req_status');
            $table->index(['driver_id', 'status'], 'idx_assignments_driver_status');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropIndex('idx_requests_status_created');
            $table->dropIndex('idx_requests_dept_status');
            $table->dropIndex('idx_requests_driver_status');
        });

        Schema::table('request_itineraries', function (Blueprint $table) {
            $table->dropIndex('idx_itineraries_req_date');
            $table->dropIndex('idx_itineraries_driver_date');
        });

        Schema::table('operational_trips', function (Blueprint $table) {
            $table->dropIndex('idx_op_trips_req_status');
            $table->dropIndex('idx_op_trips_driver_status');
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->dropIndex('idx_assignments_req_status');
            $table->dropIndex('idx_assignments_driver_status');
        });
    }
};
