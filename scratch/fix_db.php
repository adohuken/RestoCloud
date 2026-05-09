<?php
$host = 'localhost';
$db   = 'foodcorp_system';
$user = 'root';
$pass = '';

$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // 1. Alter order_details enum
    $pdo->query("ALTER TABLE order_details MODIFY COLUMN item_status ENUM('draft', 'pending', 'preparing', 'ready', 'served') NOT NULL DEFAULT 'draft'");
    echo "ENUM item_status actualizado exitosamente.\n";

} catch (\PDOException $e) {
    die("DB Error: " . $e->getMessage());
}
