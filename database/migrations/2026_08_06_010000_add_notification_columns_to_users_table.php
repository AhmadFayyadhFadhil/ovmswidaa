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
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'read_notification_ids')) {
                $table->json('read_notification_ids')->nullable()->after('can_request');
            }
            if (!Schema::hasColumn('users', 'deleted_notification_ids')) {
                $table->json('deleted_notification_ids')->nullable()->after('read_notification_ids');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'read_notification_ids')) {
                $table->dropColumn('read_notification_ids');
            }
            if (Schema::hasColumn('users', 'deleted_notification_ids')) {
                $table->dropColumn('deleted_notification_ids');
            }
        });
    }
};
