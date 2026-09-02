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
            if (!Schema::hasColumn('requests', 'coordinator_id')) {
                $table->unsignedBigInteger('coordinator_id')->nullable()->after('assigned_by');
            }
            if (!Schema::hasColumn('requests', 'coordinator_assigned_at')) {
                $table->timestamp('coordinator_assigned_at')->nullable()->after('assigned_at');
            }
            if (!Schema::hasColumn('requests', 'ga_approved_by')) {
                $table->unsignedBigInteger('ga_approved_by')->nullable()->after('coordinator_id');
            }
            if (!Schema::hasColumn('requests', 'ga_approved_at')) {
                $table->timestamp('ga_approved_at')->nullable()->after('coordinator_assigned_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $cols = ['coordinator_id', 'coordinator_assigned_at', 'ga_approved_by', 'ga_approved_at'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
