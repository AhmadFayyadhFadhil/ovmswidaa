<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\Department;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $hrGaDept = Department::where('name', 'HRD & GA')
            ->orWhere('name', 'like', '%HRD%')
            ->first();

        if ($hrGaDept) {
            DB::table('users')
                ->where(function ($q) {
                    $q->where('email', 'like', '%melody%')
                      ->orWhere('email', 'melody@widatra.com')
                      ->orWhere('nik', '1430')
                      ->orWhere('name', 'like', '%Melodi Bella Astria%');
                })
                ->update(['department_id' => $hrGaDept->id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $plantDept = Department::where('name', 'Plant Management')->first();
        if ($plantDept) {
            DB::table('users')
                ->where(function ($q) {
                    $q->where('email', 'melody@widatra.com')
                      ->orWhere('nik', '1430');
                })
                ->update(['department_id' => $plantDept->id]);
        }
    }
};
