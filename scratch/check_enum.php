<?php
$_SERVER['HTTP_HOST'] = 'localhost';
include __DIR__ . '/../config/db.php';
$stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'status'");
print_r($stmt->fetch(PDO::FETCH_ASSOC));
