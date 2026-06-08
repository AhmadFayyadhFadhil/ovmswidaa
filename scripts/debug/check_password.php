<?php
$dbfile = __DIR__ . '/../database/database.sqlite';
$pdo = new PDO('sqlite:' . $dbfile);
$stmt = $pdo->prepare('SELECT password FROM users WHERE email = ?');
$stmt->execute(['admin@ovms.test']);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo "no user\n"; exit; }
$hash = $row['password'];
var_dump($hash);
var_dump(password_verify('password', $hash));
?>