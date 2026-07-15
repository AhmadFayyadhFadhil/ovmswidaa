<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'create-request', 'view-own-request', 'view-all-requests',
            'update-request', 'delete-request', 'approve-request', 'reject-request',
            'view-vehicle', 'create-vehicle', 'update-vehicle', 'delete-vehicle',
            'view-user', 'create-user', 'update-user', 'delete-user',
            'view-audit-log', 'create-assignment', 'update-assignment', 'delete-assignment',
            'scan-request',
        ];

        // Buat permission untuk guard web DAN sanctum
        foreach (['web', 'sanctum'] as $guard) {
            foreach ($permissions as $permission) {
                Permission::firstOrCreate([
                    'name'       => $permission,
                    'guard_name' => $guard,
                ]);
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Buat role untuk guard web DAN sanctum
        foreach (['web', 'sanctum'] as $guard) {
            foreach (['Admin', 'GA', 'Approver', 'Employee', 'Driver', 'Security'] as $roleName) {
                Role::firstOrCreate([
                    'name'       => $roleName,
                    'guard_name' => $guard,
                ]);
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['web', 'sanctum'] as $guard) {
            $allPerms = Permission::where('guard_name', $guard)->get();

            $admin    = Role::where('name', 'Admin')->where('guard_name', $guard)->first();
            $ga       = Role::where('name', 'GA')->where('guard_name', $guard)->first();
            $approver = Role::where('name', 'Approver')->where('guard_name', $guard)->first();
            $employee = Role::where('name', 'Employee')->where('guard_name', $guard)->first();
            $driver   = Role::where('name', 'Driver')->where('guard_name', $guard)->first();
            $security = Role::where('name', 'Security')->where('guard_name', $guard)->first();

            $admin->syncPermissions($allPerms);

            $ga->syncPermissions($allPerms->whereIn('name', [
                'create-request',
                'view-vehicle', 'create-vehicle', 'update-vehicle', 'delete-vehicle',
                'view-all-requests', 'view-audit-log',
                'create-assignment', 'update-assignment', 'delete-assignment',
                'scan-request',
            ]));

            $approver->syncPermissions($allPerms->whereIn('name', [
                'view-all-requests', 'approve-request', 'reject-request',
                'view-vehicle', 'view-audit-log', 'create-assignment', 'update-assignment',
            ]));

            $employee->syncPermissions($allPerms->whereIn('name', [
                'create-request', 'view-own-request', 'view-vehicle',
            ]));

            $driver->syncPermissions($allPerms->whereIn('name', [
                'view-vehicle', 'view-own-request',
            ]));

            $security->syncPermissions($allPerms->whereIn('name', [
                'view-all-requests', 'scan-request'
            ]));
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
