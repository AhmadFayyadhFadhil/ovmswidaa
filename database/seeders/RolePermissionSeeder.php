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
            'create-request',
            'view-own-request',
            'view-all-requests',
            'update-request',
            'delete-request',
            'approve-request',
            'reject-request',
            'view-vehicle',
            'create-vehicle',
            'update-vehicle',
            'delete-vehicle',
            'view-user',
            'create-user',
            'update-user',
            'delete-user',
            'view-audit-log',
        ];

        // Buat permission untuk KEDUA guard: web dan api
        foreach (['web', 'api'] as $guard) {
            foreach ($permissions as $permission) {
                Permission::firstOrCreate([
                    'name'       => $permission,
                    'guard_name' => $guard,
                ]);
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $roleNames = ['Admin', 'GA', 'Approver', 'Employee', 'Driver'];

        // Buat role untuk KEDUA guard: web dan api
        foreach (['web', 'api'] as $guard) {
            foreach ($roleNames as $roleName) {
                Role::firstOrCreate([
                    'name'       => $roleName,
                    'guard_name' => $guard,
                ]);
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Assign permission ke role untuk masing-masing guard
        foreach (['web', 'api'] as $guard) {
            $allPerms = Permission::where('guard_name', $guard)->get();

            $admin    = Role::where('name', 'Admin')->where('guard_name', $guard)->first();
            $ga       = Role::where('name', 'GA')->where('guard_name', $guard)->first();
            $approver = Role::where('name', 'Approver')->where('guard_name', $guard)->first();
            $employee = Role::where('name', 'Employee')->where('guard_name', $guard)->first();
            $driver   = Role::where('name', 'Driver')->where('guard_name', $guard)->first();

            $admin->syncPermissions($allPerms);

            $ga->syncPermissions(
                $allPerms->whereIn('name', [
                    'view-vehicle', 'create-vehicle', 'update-vehicle', 'delete-vehicle',
                    'view-all-requests', 'view-audit-log',
                ])
            );

            $approver->syncPermissions(
                $allPerms->whereIn('name', [
                    'view-all-requests', 'approve-request', 'reject-request',
                    'view-vehicle', 'view-audit-log',
                ])
            );

            $employee->syncPermissions(
                $allPerms->whereIn('name', [
                    'create-request', 'view-own-request', 'view-vehicle',
                ])
            );

            $driver->syncPermissions(
                $allPerms->whereIn('name', [
                    'view-vehicle', 'view-own-request',
                ])
            );
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
}