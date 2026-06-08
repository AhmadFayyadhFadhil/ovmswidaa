<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Spatie\Permission\Models\Role;

// Configuration
$email = 'kepala.hrdga@company.com';
$name = 'Kepala Departemen HRD&GA';
$password = password_hash('password123', PASSWORD_BCRYPT); // Change this!
$department_id = 'HRD&GA';

echo "Creating Kepala Departemen HRD&GA user...\n";

// Check if user already exists
$user = User::where('email', $email)->first();

if ($user) {
    echo "User dengan email {$email} sudah ada.\n";
} else {
    // Create new user
    $user = User::create([
        'name' => $name,
        'email' => $email,
        'password' => $password,
        'department_id' => $department_id,
        'is_department_head' => true,
    ]);
    echo "User baru berhasil dibuat:\n";
    echo "  Email: {$user->email}\n";
    echo "  Name: {$user->name}\n";
    echo "  Department: {$user->department_id}\n";
    echo "  Department Head: " . ($user->is_department_head ? 'Ya' : 'Tidak') . "\n";
}

// Assign Approver role (gunakan sanctum guard karena User model pakai sanctum)
$role = Role::where('name', 'Approver')->where('guard_name', 'sanctum')->first();
if ($role) {
    $user->assignRole($role);
    echo "✓ Role 'Approver' (sanctum) berhasil di-assign\n";
} else {
    echo "✗ Role 'Approver' (sanctum) tidak ditemukan. Pastikan sudah menjalankan seeder.\n";
}

echo "\nKonfigurasi Kepala Departemen HRD&GA selesai!\n";
echo "User sekarang dapat:\n";
echo "  • Menyetujui/menolak permintaan kendaraan\n";
echo "  • Menugaskan driver ke permintaan\n";
echo "  • Melihat semua audit log\n";
