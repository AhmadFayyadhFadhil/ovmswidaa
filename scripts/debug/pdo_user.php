<?php
$host = '127.0.0.1';
$db   = 'ovms';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute(['admin@ovms.test']);
    $row = $stmt->fetch();
    if ($row) {
        echo "FOUND\n";
        print_r($row);
    } else {
        echo "NOT FOUND\n";
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
