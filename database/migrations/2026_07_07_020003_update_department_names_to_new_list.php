<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $mapping = [
            'IT' => 'Information and Technology',
            'FA' => 'Finance and Accounting',
            'HRD' => 'HRD & GA',
            'GA' => 'HRD & GA',
            'HR&GA' => 'HRD & GA',
            'HRD&GA' => 'HRD & GA',
            'SUPPLY CHAIN' => 'Supply Chain',
            'TECHNICAL' => 'Technical and Development',
            'ENGINEERING' => 'Technical and Development',
            'QA' => 'Quality Assurance',
            'QC' => 'Quality Control',
            'PRODUKSI' => 'Production',
            'HSE' => 'Plant Management',
            'SECURITY' => 'Legal & Compliance',
        ];

        foreach ($mapping as $old => $new) {
            DB::table('users')->where('department_id', $old)->update(['department_id' => $new]);
            DB::table('requests')->where('department_id', $old)->update(['department_id' => $new]);
            DB::table('passengers')->where('department_id', $old)->update(['department_id' => $new]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $reverseMapping = [
            'Information and Technology' => 'IT',
            'Finance and Accounting' => 'FA',
            'HRD & GA' => 'HRD&GA',
            'Supply Chain' => 'SUPPLY CHAIN',
            'Technical and Development' => 'TECHNICAL',
            'Quality Assurance' => 'QA',
            'Quality Control' => 'QC',
            'Production' => 'PRODUKSI',
            'Plant Management' => 'HSE',
            'Legal & Compliance' => 'SECURITY',
        ];

        foreach ($reverseMapping as $new => $old) {
            DB::table('users')->where('department_id', $new)->update(['department_id' => $old]);
            DB::table('requests')->where('department_id', $new)->update(['department_id' => $old]);
            DB::table('passengers')->where('department_id', $new)->update(['department_id' => $old]);
        }
    }
};
