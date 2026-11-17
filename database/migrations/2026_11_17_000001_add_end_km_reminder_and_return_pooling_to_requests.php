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
            if (!Schema::hasColumn('requests', 'end_km_reminder_sent_at')) {
                $table->timestamp('end_km_reminder_sent_at')->nullable()->after('end_km');
            }
            if (!Schema::hasColumn('requests', 'return_pool_group_id')) {
                $table->string('return_pool_group_id', 64)->nullable()->index()->after('pool_group_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (Schema::hasColumn('requests', 'end_km_reminder_sent_at')) {
                $table->dropColumn('end_km_reminder_sent_at');
            }
            if (Schema::hasColumn('requests', 'return_pool_group_id')) {
                $table->dropColumn('return_pool_group_id');
            }
        });
    }
};
