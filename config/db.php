<?php
// Database connection using PDO - Auto-detecting environment
$host_env = $_SERVER['HTTP_HOST'];

if ($host_env === 'localhost' || strpos($host_env, '127.0.0.1') !== false) {
    // LOCAL CONFIG (XAMPP)
    $host = 'localhost';
    $db = 'foodcorp_system';
    $user = 'root';
    $pass = '';
} else {
    // LIVE CONFIG (InfinityFree)
    $host = 'sql109.infinityfree.com';
    $db = 'if0_41605608_foodcorp_system';
    $user = 'if0_41605608';
    $pass = 'vN2z1h96I7IobCu';
}

$charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

// Set timezone for consistency
date_default_timezone_set('America/Managua');

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // In production you might log this error instead of displaying
    exit('Database connection failed: ' . $e->getMessage());
}
