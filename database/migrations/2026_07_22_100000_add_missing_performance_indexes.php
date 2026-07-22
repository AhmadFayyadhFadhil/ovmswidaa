<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add missing performance indexes to frequently-queried columns.
     * All indexes are wrapped in hasIndex checks to be idempotent/safe.
     */
    public function up(): void
    {
        // requests table
        Schema::table('requests', function (Blueprint $table) {
            // Filter requests by user (employee view) + sort
            if (!$this->indexExists('requests', 'requests_user_id_created_at_index')) {
                $table->index(['user_id', 'created_at'], 'requests_user_id_created_at_index');
            }
            // Overlap date range checks for vehicle busy detection
            if (!$this->indexExists('requests', 'requests_start_time_end_time_index')) {
                $table->index(['start_time', 'end_time'], 'requests_start_time_end_time_index');
            }
            // Vehicle pluck for busy list
            if (!$this->indexExists('requests', 'requests_vehicle_id_index')) {
                $table->index('vehicle_id', 'requests_vehicle_id_index');
            }
        });

        // users table
        Schema::table('users', function (Blueprint $table) {
            // GA/approver head check
            if (!$this->indexExists('users', 'users_department_id_is_dept_head_index')) {
                $table->index(['department_id', 'is_department_head'], 'users_department_id_is_dept_head_index');
            }
            // Driver availability filter
            if (!$this->indexExists('users', 'users_availability_status_index')) {
                $table->index('availability_status', 'users_availability_status_index');
            }
        });

        // vehicles table
        Schema::table('vehicles', function (Blueprint $table) {
            // Filter available vehicles
            if (!$this->indexExists('vehicles', 'vehicles_status_index')) {
                $table->index('status', 'vehicles_status_index');
            }
        });

        // passengers table
        Schema::table('passengers', function (Blueprint $table) {
            // orWhereHas('passengers') for employee request list
            if (!$this->indexExists('passengers', 'passengers_user_id_index')) {
                $table->index('user_id', 'passengers_user_id_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropIndexIfExists('requests_user_id_created_at_index');
            $table->dropIndexIfExists('requests_start_time_end_time_index');
            $table->dropIndexIfExists('requests_vehicle_id_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndexIfExists('users_department_id_is_dept_head_index');
            $table->dropIndexIfExists('users_availability_status_index');
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndexIfExists('vehicles_status_index');
        });

        Schema::table('passengers', function (Blueprint $table) {
            $table->dropIndexIfExists('passengers_user_id_index');
        });
    }

    /**
     * Helper: check if an index already exists to prevent duplicate index errors.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = \Illuminate\Support\Facades\DB::select(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
            [$indexName]
        );
        return !empty($indexes);
    }
};
