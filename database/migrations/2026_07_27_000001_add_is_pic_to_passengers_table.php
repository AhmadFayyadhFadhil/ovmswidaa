<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('passengers') && !Schema::hasColumn('passengers', 'is_pic')) {
            Schema::table('passengers', function (Blueprint $table) {
                $table->boolean('is_pic')->default(false)->after('department_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('passengers') && Schema::hasColumn('passengers', 'is_pic')) {
            Schema::table('passengers', function (Blueprint $table) {
                $table->dropColumn('is_pic');
            });
        }
    }
};
