<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Spatie\Permission\Models\Role;

$role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
$user = User::where('email', 'admin@test.com')->first();
if (!$user) {
    echo "User not found\n";
    exit(1);
}

$user->assignRole('Admin');
echo "Assigned Admin role to {$user->email}\n";
