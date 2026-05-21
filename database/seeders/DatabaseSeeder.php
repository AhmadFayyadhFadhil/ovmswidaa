<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Run role & permission seeder first
        $this->call(RolePermissionSeeder::class);

        // Create default Admin user
        $admin = User::factory()->create([
            'name'     => 'Administrator',
            'email'    => 'admin@ovms.test',
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole('Admin');

        // Create default GA user
        $ga = User::factory()->create([
            'name'     => 'General Affairs',
            'email'    => 'ga@ovms.test',
            'password' => Hash::make('password'),
        ]);
        $ga->assignRole('GA');

        // Create default Approver user
        $approver = User::factory()->create([
            'name'     => 'Manager Approver',
            'email'    => 'approver@ovms.test',
            'password' => Hash::make('password'),
        ]);
        $approver->assignRole('Approver');

        // Create default Employee user
        $employee = User::factory()->create([
            'name'     => 'Employee Test',
            'email'    => 'employee@ovms.test',
            'password' => Hash::make('password'),
        ]);
        $employee->assignRole('Employee');

        // Create default Driver user
        $driver = User::factory()->create([
            'name'     => 'Driver Test',
            'email'    => 'driver@ovms.test',
            'password' => Hash::make('password'),
        ]);
        $driver->assignRole('Driver');
    }
}
