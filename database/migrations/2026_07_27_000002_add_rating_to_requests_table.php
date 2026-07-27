<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('requests')) {
            Schema::table('requests', function (Blueprint $table) {
                if (!Schema::hasColumn('requests', 'rating')) {
                    $table->unsignedTinyInteger('rating')->nullable()->after('status');
                }
                if (!Schema::hasColumn('requests', 'rating_notes')) {
                    $table->text('rating_notes')->nullable()->after('rating');
                }
                if (!Schema::hasColumn('requests', 'rated_at')) {
                    $table->timestamp('rated_at')->nullable()->after('rating_notes');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('requests')) {
            Schema::table('requests', function (Blueprint $table) {
                $columnsToDrop = [];
                if (Schema::hasColumn('requests', 'rating')) $columnsToDrop[] = 'rating';
                if (Schema::hasColumn('requests', 'rating_notes')) $columnsToDrop[] = 'rating_notes';
                if (Schema::hasColumn('requests', 'rated_at')) $columnsToDrop[] = 'rated_at';

                if (!empty($columnsToDrop)) {
                    $table->dropColumn($columnsToDrop);
                }
            });
        }
    }
};
