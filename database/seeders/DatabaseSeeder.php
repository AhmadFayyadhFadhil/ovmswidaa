<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $admin = User::updateOrCreate(
            ['email' => 'admin@ovms.test'],
            ['name' => 'Administrator', 'password' => Hash::make('password'), 'department_id' => null]
        );
        $admin->assignRole('Admin');

        $ga = User::updateOrCreate(
            ['email' => 'ga@ovms.test'],
            ['name' => 'General Affairs', 'password' => Hash::make('password'), 'department_id' => null]
        );
        $ga->assignRole('GA');

        $approver = User::updateOrCreate(
            ['email' => 'approver@ovms.test'],
            ['name' => 'Manager Approver', 'password' => Hash::make('password'), 'department_id' => 'IT', 'rank' => 'Manager', 'is_department_head' => true]
        );
        $approver->assignRole('Approver');

        $employee = User::updateOrCreate(
            ['email' => 'employee@ovms.test'],
            ['name' => 'Employee Test', 'password' => Hash::make('password'), 'department_id' => 'IT']
        );
        $employee->assignRole('Employee');

        $driver = User::updateOrCreate(
            ['email' => 'driver@ovms.test'],
            ['name' => 'Driver Test', 'password' => Hash::make('password'), 'department_id' => null, 'availability_status' => 'available']
        );
        $driver->assignRole('Driver');
    }
}
