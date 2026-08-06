<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$reqs = App\Models\Request::orderBy('id', 'desc')->take(20)->get();
foreach ($reqs as $r) {
    $st = is_object($r->status) ? $r->status->value : $r->status;
    echo "ID: {$r->id} | DB status: {$st} | Employee: {$r->user?->name}\n";
}
