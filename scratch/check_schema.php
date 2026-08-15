<?php
require_once __DIR__ . '/../config/db.php';
$stmt = $pdo->query("DESCRIBE order_details");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($columns);
