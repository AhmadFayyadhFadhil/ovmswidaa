<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Department;

return new class extends Migration
{
    public function up(): void
    {
        $secDept = Department::where('name', 'like', '%Legal%')
            ->orWhere('name', 'like', '%HR%')
            ->first();
        $deptId = $secDept ? $secDept->id : null;

        // Find existing security user or create new one with username/nik: satpam
        $securityUser = User::where('nik', 'satpam')
            ->orWhere('nik', 'SEC001')
            ->orWhere('email', 'security@widatra.com')
            ->first();

        if (!$securityUser) {
            $securityUser = new User();
        }

        $securityUser->nik = 'satpam';
        $securityUser->name = 'Pos Keamanan Gerbang';
        $securityUser->email = 'security@widatra.com';
        $securityUser->password = Hash::make('wbstpm');
        if ($deptId && empty($securityUser->department_id)) {
            $securityUser->department_id = $deptId;
        }
        $securityUser->is_department_head = false;
        $securityUser->is_active = true;
        $securityUser->save();

        if (method_exists($securityUser, 'syncRoles')) {
            $securityUser->syncRoles(['Security']);
        }
    }

    public function down(): void
    {
        // No destructive rollback needed
    }
};
