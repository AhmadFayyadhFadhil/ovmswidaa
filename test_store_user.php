<?php
/**
 * Test API Store User Endpoint
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Laravel\Sanctum\Sanctum;

echo "\n========== TEST STORE USER ENDPOINT ==========\n\n";

// Get admin user
$admin = User::where('email', 'admin@ovms.test')->first();

if (!$admin) {
    echo "❌ Admin user not found!\n";
    exit;
}

echo "✓ Found admin user: {$admin->name}\n";
echo "✓ Admin roles: " . $admin->roles->pluck('name')->join(', ') . "\n\n";

// Authenticate as admin
Sanctum::actingAs($admin);

echo "Creating test user via store method...\n\n";

// Simulate API request
$controller = new \App\Http\Controllers\Api\UserController();

$request = new \Illuminate\Http\Request();
$request->merge([
    'name' => 'Test User Store',
    'email' => 'test.store.' . time() . '@ovms.test',
    'password' => 'password123',
    'role' => 'Employee',
]);

try {
    $response = $controller->store($request);
    $statusCode = $response->getStatusCode();
    $content = json_decode($response->content(), true);
    
    echo "Status Code: {$statusCode}\n";
    echo "Response: " . json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
    
    if ($statusCode === 201) {
        echo "✓ User created successfully!\n";
        
        // Verify in database
        $newUser = User::where('email', $content['data']['email'])->first();
        if ($newUser) {
            echo "✓ User found in database: {$newUser->name}\n";
            echo "  - Email: {$newUser->email}\n";
            echo "  - Roles: " . $newUser->roles->pluck('name')->join(', ') . "\n";
        } else {
            echo "❌ User NOT found in database!\n";
        }
    } else {
        echo "❌ Failed to create user\n";
    }
} catch (\Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " ({$e->getLine()})\n";
}

echo "\n";
