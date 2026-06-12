<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

use Illuminate\Support\Facades\DB;

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $roles = DB::table('model_has_roles')
        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->select('model_has_roles.model_id', 'roles.name as role_name', 'model_has_roles.model_type', 'roles.guard_name as role_guard')
        ->get();
        
    echo "Model Has Roles contents:\n";
    foreach ($roles as $r) {
        echo "- Model ID: {$r->model_id}, Role: {$r->role_name}, Role Guard: {$r->role_guard}\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
