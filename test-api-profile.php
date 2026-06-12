<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

use App\Models\User;

// 1. Login
$ch = curl_init('http://localhost:8000/api/login');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'email' => 'employee@ovms.test',
    'password' => 'password'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
$res = curl_exec($ch);
curl_close($ch);

$loginData = json_decode($res, true);
if (!isset($loginData['data']['token'])) {
    echo "Login failed: " . $res . "\n";
    exit(1);
}
$token = $loginData['data']['token'];
echo "Logged in successfully. Token: " . substr($token, 0, 15) . "...\n";

// 2. Fetch original profile
$ch = curl_init('http://localhost:8000/api/profile');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Accept: application/json'
]);
$res = curl_exec($ch);
curl_close($ch);
$profileData = json_decode($res, true);
echo "Original Profile: Name = " . $profileData['data']['name'] . ", Email = " . $profileData['data']['email'] . "\n";

// 3. Update Profile
$ch = curl_init('http://localhost:8000/api/profile');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'name' => 'Employee Test Updated',
    'email' => 'employee@ovms.test'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json',
    'Accept: application/json'
]);
$res = curl_exec($ch);
curl_close($ch);
$updateData = json_decode($res, true);
echo "Update response status: " . ($updateData['status'] ?? 'unknown') . "\n";
echo "Updated Profile in response: Name = " . ($updateData['data']['name'] ?? 'none') . "\n";

// 4. Fetch profile again to see if it changed
$ch = curl_init('http://localhost:8000/api/profile');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Accept: application/json'
]);
$res = curl_exec($ch);
curl_close($ch);
$profileData2 = json_decode($res, true);
echo "After Update Profile: Name = " . ($profileData2['data']['name'] ?? 'none') . ", Email = " . ($profileData2['data']['email'] ?? 'none') . "\n";

// 5. Query DB directly
$user = User::where('email', 'employee@ovms.test')->first();
echo "Direct DB query: Name = " . $user->name . ", Email = " . $user->email . "\n";

// Restore original
$user->name = "Employee Test";
$user->save();
echo "Restored original name in database.\n";
