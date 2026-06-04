<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$user = DB::table('users')->where('email', 'admin@ovms.test')->first();
if ($user) {
	echo "FOUND\n";
	print_r($user);
} else {
	echo "NOT FOUND\n";
}
