<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Ensure Plant Management department exists
        $pmDept = DB::table('departments')->where('name', 'Plant Management')->first();
        if (!$pmDept) {
            $pmDeptId = DB::table('departments')->insertGetId([
                'name' => 'Plant Management',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $pmDeptId = $pmDept->id;
        }

        // 2. Assign Plant Management department to user TESTPM (ID 26, name TESTPM, or email asd@gmail.com)
        DB::table('users')
            ->where('id', 26)
            ->orWhere('name', 'TESTPM')
            ->orWhere('email', 'asd@gmail.com')
            ->update([
                'department_id' => $pmDeptId,
                'rank'          => DB::raw("COALESCE(NULLIF(rank, ''), 'Plant Management')"),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse operation needed
    }
};
