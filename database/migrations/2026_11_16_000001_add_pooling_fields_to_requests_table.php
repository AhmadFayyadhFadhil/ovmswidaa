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
            if (!Schema::hasColumn('requests', 'pool_group_id')) {
                $table->string('pool_group_id', 64)->nullable()->index()->after('trip_mode');
            }
            if (!Schema::hasColumn('requests', 'is_pooled')) {
                $table->boolean('is_pooled')->default(false)->after('pool_group_id');
            }
            if (!Schema::hasColumn('requests', 'master_pool_qr')) {
                $table->string('master_pool_qr', 100)->nullable()->index()->after('is_pooled');
            }
            if (!Schema::hasColumn('requests', 'chained_from_request_id')) {
                $table->unsignedBigInteger('chained_from_request_id')->nullable()->index()->after('master_pool_qr');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $cols = ['chained_from_request_id', 'master_pool_qr', 'is_pooled', 'pool_group_id'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
