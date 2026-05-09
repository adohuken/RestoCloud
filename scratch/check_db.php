<?php
$_SERVER['HTTP_HOST'] = 'localhost';
include __DIR__ . '/../config/db.php';
$stmt = $pdo->query("SELECT id, status FROM orders WHERE table_id = 1 AND status != 'completed' AND status != 'cancelled'");
print_r($stmt->fetchAll());

$stmt2 = $pdo->query("SELECT id, item_status FROM order_details WHERE order_id = 3");
print_r($stmt2->fetchAll());
