<?php
$dbfile = __DIR__ . '/../database/database.sqlite';
if (!file_exists($dbfile)) { echo "No sqlite file\n"; exit(1); }
try {
    $pdo = new PDO('sqlite:' . $dbfile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute(['admin@ovms.test']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo "FOUND\n";
        print_r($row);
    } else {
        echo "NOT FOUND\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
