<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add nik column to users table
        Schema::table('users', function (Blueprint $table) {
            $table->string('nik')->nullable()->unique()->after('id');
        });

        // 2. Re-align department IDs (start from 8 to 18)
        Schema::disableForeignKeyConstraints();

        DB::table('departments')->truncate();

        $depts = [
            8 => 'Information and Technology',
            9 => 'Finance and Accounting',
            10 => 'HRD & GA',
            11 => 'Supply Chain',
            12 => 'Technical and Development',
            13 => 'Quality Assurance',
            14 => 'Quality Control',
            15 => 'Production',
            16 => 'Regulatory Affairs & PV',
            17 => 'Legal & Compliance',
            18 => 'Plant Management',
        ];

        foreach ($depts as $id => $name) {
            DB::table('departments')->insert([
                'id' => $id,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Remove nik column from users table
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('nik');
        });

        // 2. Revert department IDs alignment (1 to 11)
        Schema::disableForeignKeyConstraints();

        DB::table('departments')->truncate();

        $departments = [
            'Information and Technology',
            'Finance and Accounting',
            'HRD & GA',
            'Supply Chain',
            'Technical and Development',
            'Quality Assurance',
            'Quality Control',
            'Production',
            'Regulatory Affairs & PV',
            'Legal & Compliance',
            'Plant Management',
        ];

        foreach ($departments as $name) {
            DB::table('departments')->insert([
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::enableForeignKeyConstraints();
    }
};
