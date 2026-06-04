<?php
$url = 'http://localhost:8000/api/login';
$data = ['email'=>'admin@ovms.test','password'=>'password'];
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "HTTP_CODE: $code\n";
echo $res . "\n";
if (curl_errno($ch)) {
    echo 'CURL ERROR: ' . curl_error($ch) . "\n";
}
curl_close($ch);
