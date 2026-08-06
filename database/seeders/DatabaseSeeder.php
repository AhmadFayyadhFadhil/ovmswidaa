<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Department;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        // Helper to resolve department_id dynamically by name
        $getDeptId = function (string $deptName): int {
            return Department::firstOrCreate(['name' => trim($deptName)])->id;
        };

        // Ensure default departments exist
        $standardDepts = [
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
        foreach ($standardDepts as $dName) {
            $getDeptId($dName);
        }

        // Official Approvers list matching official company structure
        $officialApprovers = [
            ['nik' => '10053', 'name' => 'Evalin Jayakusli',        'email' => 'evalin@widatra.com',  'dept' => 'Legal & Compliance',         'roles' => ['Approver']],
            ['nik' => '1430',  'name' => 'Melodi Bella Astria',     'email' => 'melody@widatra.com',  'dept' => 'Plant Management',           'roles' => ['Approver', 'GA']],
            ['nik' => '10319', 'name' => 'Gita Thessa Lonika Putri', 'email' => 'gita@widatra.com',    'dept' => 'Regulatory Affairs & PV',    'roles' => ['Approver']],
            ['nik' => '790',   'name' => 'Hendri Yanto Prabowo',    'email' => 'hendri@widatra.com',  'dept' => 'Quality Control',            'roles' => ['Approver']],
            ['nik' => '786',   'name' => 'Rizky Bagus Kurniawan',   'email' => 'rizky@widatra.com',   'dept' => 'Production',                 'roles' => ['Approver']],
            ['nik' => '1135',  'name' => 'Arfian Arianto',          'email' => 'arfian@widatra.com',  'dept' => 'Quality Assurance',          'roles' => ['Approver']],
            ['nik' => '834',   'name' => 'Hendri Hardian',          'email' => 'hardian@widatra.com', 'dept' => 'Supply Chain',               'roles' => ['Approver']],
            ['nik' => '817',   'name' => 'Yogi Wicaksono',          'email' => 'yogi@widatra.com',    'dept' => 'Technical and Development',  'roles' => ['Approver']],
            ['nik' => '1095',  'name' => 'Andaru Wana Perkasa',     'email' => 'andaru@widatra.com',  'dept' => 'HRD & GA',                   'roles' => ['Approver']],
            ['nik' => '1556',  'name' => 'Johny Santoso',           'email' => 'johny@widatra.com',   'dept' => 'Finance and Accounting',     'roles' => ['Approver']],
            ['nik' => '1125',  'name' => 'Prind Widjaya Sena',      'email' => 'sena@widatra.com',    'dept' => 'Information and Technology', 'roles' => ['Approver']],
        ];

        foreach ($officialApprovers as $item) {
            $deptId = $getDeptId($item['dept']);
            $user = User::where('email', $item['email'])
                ->orWhere('nik', $item['nik'])
                ->first();

            if (!$user) {
                $user = new User();
                $user->password = Hash::make('password');
            }

            $user->nik = $item['nik'];
            $user->name = $item['name'];
            $user->email = $item['email'];
            $user->department_id = $deptId;
            $user->is_department_head = true;
            $user->is_active = true;
            $user->can_request = true;
            $user->rank = 'Kepala Departemen';
            $user->save();

            $user->syncRoles($item['roles']);
        }

        // Additional employees
        $employeesData = [
            ['nik' => 'SA12345', 'name' => 'Super Admin User',      'email' => 'superadmin@example.com', 'dept' => null,                         'role' => 'Admin'],
            ['nik' => '1393',    'name' => 'Khasanudin',           'email' => 'khasanudin@gmail.com',   'dept' => 'Production',                 'role' => 'Employee'],
            ['nik' => '73250',   'name' => 'Dimas Subiyantoro',    'email' => 'it.factory.dimas@widatra.com', 'dept' => 'Information and Technology', 'role' => 'Employee'],
            ['nik' => '73331',   'name' => 'Muhammad Jihan Gumeular', 'email' => 'it.factory@widatra.com', 'dept' => 'Information and Technology', 'role' => 'Employee'],
        ];

        foreach ($employeesData as $ed) {
            $user = User::where('email', $ed['email'])->orWhere('nik', $ed['nik'])->first();
            if (!$user) {
                $user = new User();
                $user->password = Hash::make('password');
            }
            $user->nik = $ed['nik'];
            $user->name = $ed['name'];
            $user->email = $ed['email'];
            $user->department_id = $ed['dept'] ? $getDeptId($ed['dept']) : null;
            $user->is_department_head = false;
            $user->is_active = true;
            $user->can_request = true;
            $user->save();

            $user->syncRoles([$ed['role']]);
        }

        // Seed drivers
        $drivers = [
            ['nik' => 'DRV001', 'name' => 'Driver Test 1', 'email' => 'driver1@widatra.com'],
            ['nik' => 'DRV002', 'name' => 'Driver Test 2', 'email' => 'driver2@widatra.com'],
        ];

        $plantDeptId = $getDeptId('Plant Management');
        foreach ($drivers as $d) {
            $user = User::where('email', $d['email'])->orWhere('nik', $d['nik'])->first();
            if (!$user) {
                $user = new User();
                $user->password = Hash::make('password');
            }
            $user->nik = $d['nik'];
            $user->name = $d['name'];
            $user->email = $d['email'];
            $user->department_id = $plantDeptId;
            $user->is_department_head = false;
            $user->availability_status = 'available';
            $user->availability_start = '07:30';
            $user->availability_end = '16:30';
            $user->is_active = true;
            $user->save();

            $user->syncRoles(['Driver']);
        }

        // Seed security guard
        $secDeptId = $getDeptId('Legal & Compliance');
        $security = User::where('email', 'security@widatra.com')->orWhere('nik', 'SEC001')->first();
        if (!$security) {
            $security = new User();
            $security->password = Hash::make('password');
        }
        $security->nik = 'SEC001';
        $security->name = 'Security Guard Test';
        $security->email = 'security@widatra.com';
        $security->department_id = $secDeptId;
        $security->is_department_head = false;
        $security->is_active = true;
        $security->save();

        $security->syncRoles(['Security']);

        // Seed settings
        \App\Models\Setting::firstOrCreate(['key' => 'system_name'], ['value' => 'OVMS PT Widatra Bhakti', 'type' => 'string']);
        \App\Models\Setting::firstOrCreate(['key' => 'company_name'], ['value' => 'PT Widatra Bhakti', 'type' => 'string']);
        \App\Models\Setting::firstOrCreate(['key' => 'support_email'], ['value' => 'support@widatra.com', 'type' => 'string']);
        \App\Models\Setting::updateOrCreate(
            ['key' => 'hq_address'],
            ['value' => 'Jl. Stadion / Jl. Sidomukti No. 1, Sidomukti, Kecamatan Pandaan, Kabupaten Pasuruan, Jawa Timur 67156', 'type' => 'string']
        );

        User::query()->update([
            'location' => 'Pandaan Head Office'
        ]);
    }
}
