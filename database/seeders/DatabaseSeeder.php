<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        // Clear existing users and roles
        Schema::disableForeignKeyConstraints();
        DB::table('users')->truncate();
        DB::table('model_has_roles')->truncate();
        DB::table('model_has_permissions')->truncate();
        Schema::enableForeignKeyConstraints();

        // 38 Users from sheet
        $usersData = [
            [
                'nik' => 'SA12345',
                'name' => 'Super Admin User',
                'email' => 'superadmin@example.com',
                'department_id' => null,
                'role' => 'Admin',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '1393',
                'name' => 'Khasanudin',
                'email' => 'khasanudin@gmail.com',
                'department_id' => 8,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '73250',
                'name' => 'Dimas Subiyantoro',
                'email' => 'it.factory.dimas@widatra.com',
                'department_id' => 8,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '1125',
                'name' => 'Prind Widjaya Sena',
                'email' => 'sena@widatra.com',
                'department_id' => 8,
                'role' => 'Approver',
                'is_department_head' => true,
                'rank' => 'Kepala Departemen',
            ],
            [
                'nik' => '1556',
                'name' => 'Johny Santoso',
                'email' => 'johny@widatra.com',
                'department_id' => 9,
                'role' => 'Approver',
                'is_department_head' => true,
                'rank' => 'Kepala Departemen',
            ],
            [
                'nik' => '1095',
                'name' => 'Andaru Wana Perkasa',
                'email' => 'andaru@widatra.com',
                'department_id' => 10,
                'role' => 'Approver',
                'is_department_head' => true,
                'rank' => 'Kepala Departemen',
            ],
            [
                'nik' => '834',
                'name' => 'Hendri Hardian',
                'email' => 'hardian@widatra.com',
                'department_id' => 11,
                'role' => 'Approver',
                'is_department_head' => true,
                'rank' => 'Kepala Departemen',
            ],
            [
                'nik' => '817',
                'name' => 'Yogi Wicaksono',
                'email' => 'yogi@widatra.com',
                'department_id' => 12,
                'role' => 'Approver',
                'is_department_head' => true,
                'rank' => 'Kepala Departemen',
            ],
            [
                'nik' => '1135',
                'name' => 'Arfian Arianto',
                'email' => 'arfian@widatra.com',
                'department_id' => 13,
                'role' => 'Approver',
                'is_department_head' => true,
                'rank' => 'Kepala Departemen',
            ],
            [
                'nik' => '790',
                'name' => 'Hendri Yanto Prabowo',
                'email' => 'hendri@widatra.com',
                'department_id' => 14,
                'role' => 'Approver',
                'is_department_head' => true,
                'rank' => 'Kepala Departemen',
            ],
            [
                'nik' => '786',
                'name' => 'Rizky Bagus Kurniawan',
                'email' => 'rizky@widatra.com',
                'department_id' => 15,
                'role' => 'Approver',
                'is_department_head' => true,
                'rank' => 'Kepala Departemen',
            ],
            [
                'nik' => '10319',
                'name' => 'Gita Thessa Lonika Putri',
                'email' => 'gita@widatra.com',
                'department_id' => 16,
                'role' => 'Approver',
                'is_department_head' => true,
                'rank' => 'Kepala Departemen',
            ],
            [
                'nik' => '10053',
                'name' => 'Evalin Jayakusli',
                'email' => 'evalin@widatra.com',
                'department_id' => 17,
                'role' => 'Approver',
                'is_department_head' => true,
                'rank' => 'Kepala Departemen',
            ],
            [
                'nik' => '1430',
                'name' => 'Melodi Bella Astria',
                'email' => 'melody@widatra.com',
                'department_id' => 18,
                'role' => 'Approver_GA',
                'is_department_head' => true,
                'rank' => 'Kepala Departemen',
            ],
            [
                'nik' => '2330',
                'name' => 'Alvin Maulana',
                'email' => 'accounting@widatra.com',
                'department_id' => 9,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '392',
                'name' => 'Deni Dwi Rosidi',
                'email' => 'legal.admin@widatra.com',
                'department_id' => 10,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '241',
                'name' => 'Mulyani Lestari',
                'email' => 'lestari@widatra.com',
                'department_id' => 11,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '1425',
                'name' => 'Puput Wahyuni',
                'email' => 'adminproc@widatra.com',
                'department_id' => 11,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '1104',
                'name' => 'Murti Allahah Agustya',
                'email' => 'mumu@widatra.com',
                'department_id' => 12,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '685',
                'name' => 'Ari Adi Tama',
                'email' => 'adm.qa@widatra.com',
                'department_id' => 13,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '905',
                'name' => 'Dwi Puji Lestari',
                'email' => 'dwi@widatra.com',
                'department_id' => 14,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '1086',
                'name' => 'Sri Hidayati',
                'email' => 'ida@widatra.com',
                'department_id' => 15,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '1390',
                'name' => 'Nanang',
                'email' => 'nanang@gmail.com',
                'department_id' => 15,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '1417',
                'name' => 'Yogi',
                'email' => 'yogi@gmail.com',
                'department_id' => 15,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '1562',
                'name' => 'Mujahid',
                'email' => 'mujahid@gmail.com',
                'department_id' => 15,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '254',
                'name' => 'Sugi',
                'email' => 'sugi@gmail.com',
                'department_id' => 15,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '1374',
                'name' => 'Hanafi',
                'email' => 'hanafi@gmail.com',
                'department_id' => 15,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '1394',
                'name' => 'Ageng',
                'email' => 'ageng@gmail.com',
                'department_id' => 15,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '1376',
                'name' => 'Muni',
                'email' => 'muni@gmail.com',
                'department_id' => 15,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '1433',
                'name' => 'Anang',
                'email' => 'anang@gmail.com',
                'department_id' => 15,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '1568',
                'name' => 'Ridho',
                'email' => 'ridho@gmail.com',
                'department_id' => 15,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '362',
                'name' => 'Dwi',
                'email' => 'dwi@gmail.com',
                'department_id' => 15,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '1931',
                'name' => 'Farid',
                'email' => 'farid@gmail.com',
                'department_id' => 15,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '1395',
                'name' => 'Zainul',
                'email' => 'zainul@gmail.com',
                'department_id' => 15,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '81',
                'name' => 'Prayitno',
                'email' => 'prayitno@gmail.com',
                'department_id' => 15,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '1591',
                'name' => 'Aji',
                'email' => 'aji@gmail.com',
                'department_id' => 15,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '100626',
                'name' => 'Utility',
                'email' => 'utility@widatra.com',
                'department_id' => 12,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
            [
                'nik' => '73331',
                'name' => 'Muhammad Jihan Gumeular',
                'email' => 'it.factory@widatra.com',
                'department_id' => 8,
                'role' => 'Employee',
                'is_department_head' => false,
                'rank' => null,
            ],
        ];

        foreach ($usersData as $ud) {
            $user = User::create([
                'nik' => $ud['nik'],
                'name' => $ud['name'],
                'email' => $ud['email'],
                'password' => Hash::make('password'),
                'department_id' => $ud['department_id'],
                'is_department_head' => $ud['is_department_head'],
                'rank' => $ud['rank'],
                'is_active' => true,
            ]);

            if ($ud['role'] === 'Approver_GA') {
                $user->assignRole('Approver');
                $user->assignRole('GA');
            } else {
                $user->assignRole($ud['role']);
            }
        }

        // Seeding 2 drivers for testing
        $driver1 = User::create([
            'nik' => 'DRV001',
            'name' => 'Driver Test 1',
            'email' => 'driver1@widatra.com',
            'password' => Hash::make('password'),
            'department_id' => 18, // Plant Management
            'is_department_head' => false,
            'availability_status' => 'available',
            'is_active' => true,
        ]);
        $driver1->assignRole('Driver');

        $driver2 = User::create([
            'nik' => 'DRV002',
            'name' => 'Driver Test 2',
            'email' => 'driver2@widatra.com',
            'password' => Hash::make('password'),
            'department_id' => 18, // Plant Management
            'is_department_head' => false,
            'availability_status' => 'available',
            'is_active' => true,
        ]);
        $driver2->assignRole('Driver');

        // Seeding 1 security guard for testing
        $security = User::create([
            'nik' => 'SEC001',
            'name' => 'Security Guard Test',
            'email' => 'security@widatra.com',
            'password' => Hash::make('password'),
            'department_id' => 17, // Legal & Compliance
            'is_department_head' => false,
            'is_active' => true,
        ]);
        $security->assignRole('Security');
    }
}
