<?php
$dbfile = __DIR__ . '/../database/database.sqlite';
if (!file_exists($dbfile)) { echo "No sqlite file\n"; exit(1); }
try {
    $pdo = new PDO('sqlite:' . $dbfile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $hash = password_hash('password', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE email = ?');
    $stmt->execute([$hash, 'admin@ovms.test']);
    echo "Updated: " . $stmt->rowCount() . " rows\n";
    $stmt = $pdo->prepare('SELECT password FROM users WHERE email = ?');
    $stmt->execute(['admin@ovms.test']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    print_r($row);
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
