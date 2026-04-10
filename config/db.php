<?php
// Database connection using PDO for XAMPP default settings
$host = 'localhost';
$db = 'foodcorp_system';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

// Set timezone for consistency (assuming Central America based on C$ currency)
date_default_timezone_set('America/Managua');

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // In production you might log this error instead of displaying
    // In production you might log this error instead of displaying
    exit('Database connection failed: ' . $e->getMessage());
}
