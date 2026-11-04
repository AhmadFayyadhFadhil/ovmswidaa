<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure create-request and view-own-request exist for web & sanctum
        foreach (['web', 'sanctum'] as $guard) {
            foreach (['create-request', 'view-own-request'] as $permName) {
                Permission::firstOrCreate([
                    'name' => $permName,
                    'guard_name' => $guard,
                ]);
            }

            // Find all driver coordinator role variations
            $roles = Role::whereIn('name', ['Driver Coordinator', 'driver coordinator', 'coordinator'])
                ->where('guard_name', $guard)
                ->get();

            foreach ($roles as $role) {
                $role->givePermissionTo(['create-request', 'view-own-request']);
            }
        }

        // Forget cached permissions so changes take effect immediately
        try {
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        } catch (\Throwable $e) {}
    }

    public function down(): void
    {
        // No destructive rollback needed
    }
};
