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
            if (!Schema::hasColumn('requests', 'ga_approved_by_name')) {
                $table->string('ga_approved_by_name')->nullable()->after('ga_approved_by');
            }
            if (!Schema::hasColumn('requests', 'ga_approval_source')) {
                $table->string('ga_approval_source')->nullable()->after('ga_approved_by_name');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $cols = ['ga_approved_by_name', 'ga_approval_source'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
