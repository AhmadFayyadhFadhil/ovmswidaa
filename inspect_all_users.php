<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Spatie\Permission\Models\Role;

echo "=== INSPECTING ALL USERS AND ROLES IN DATABASE ===\n";

$users = User::all();
echo "Total Users in DB: " . $users->count() . "\n\n";

foreach ($users as $u) {
    $roles = $u->roles()->pluck('name')->toArray();
    echo sprintf(
        "ID: %-3d | NIK: %-10s | Name: %-25s | Email: %-30s | Roles: [%s]\n",
        $u->id,
        $u->nik ?? 'NULL',
        $u->name,
        $u->email ?? 'NULL',
        implode(', ', $roles)
    );
}

echo "\n--- AVAILABLE SPATIE ROLES ---\n";
foreach (Role::all() as $r) {
    echo "Role ID: {$r->id} | Name: {$r->name}\n";
}
