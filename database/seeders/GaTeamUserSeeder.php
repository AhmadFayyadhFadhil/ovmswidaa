<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Department;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class GaTeamUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Pastikan role GA terdaftar
        Role::firstOrCreate(['name' => 'GA', 'guard_name' => 'web']);

        // 2. Pastikan departemen HRD & GA ada
        $dept = Department::firstOrCreate(['name' => 'HRD & GA']);

        // 3. Buat / sinkronkan user GATEAM
        $user = User::where('nik', 'GATEAM')
            ->orWhere('email', 'gateam@widatra.com')
            ->first();

        if (!$user) {
            $user = new User();
            $user->password = Hash::make('password'); // Password default: password
        }

        $user->nik = 'GATEAM';
        $user->name = 'GA Team (Backup Account)';
        $user->email = 'gateam@widatra.com';
        $user->department_id = $dept->id;
        $user->is_department_head = false;
        $user->is_active = true;
        $user->can_request = true;
        $user->save();

        $user->syncRoles(['GA']);

        if (isset($this->command)) {
            $this->command->info("User GATEAM berhasil dibuat / disinkronkan: Email: gateam@widatra.com | Password: password");
        }
    }
}
