<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Define all permissions
        $permissions = [
            // Request permissions
            'create-request',
            'view-own-request',
            'view-all-requests',
            'update-request',
            'delete-request',
            'approve-request',
            'reject-request',
            
            // Vehicle permissions
            'view-vehicle',
            'create-vehicle',
            'update-vehicle',
            'delete-vehicle',
            
            // User permissions
            'view-user',
            'create-user',
            'update-user',
            'delete-user',
            
            // Audit log permissions
            'view-audit-log',
        ];

        // Create permissions for web guard (default)
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Clear cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create roles for web guard (default)
        $admin = Role::firstOrCreate(['name' => 'Admin']);
        $ga = Role::firstOrCreate(['name' => 'GA']);
        $approver = Role::firstOrCreate(['name' => 'Approver']);
        $employee = Role::firstOrCreate(['name' => 'Employee']);
        $driver = Role::firstOrCreate(['name' => 'Driver']);

        // Create roles for sanctum guard
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'GA', 'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'Approver', 'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'Driver', 'guard_name' => 'sanctum']);

        // Clear cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Assign permissions to web guard roles
        $admin->syncPermissions(Permission::all());

        $ga->syncPermissions([
            'view-vehicle',
            'create-vehicle',
            'update-vehicle',
            'delete-vehicle',
            'view-all-requests',
            'view-audit-log',
        ]);

        $approver->syncPermissions([
            'view-all-requests',
            'approve-request',
            'reject-request',
            'view-vehicle',
            'view-audit-log',
        ]);

        $employee->syncPermissions([
            'create-request',
            'view-own-request',
            'view-vehicle',
        ]);

        $driver->syncPermissions([
            'view-vehicle',
            'view-own-request',
        ]);

        // Clear cache after all assignments
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
