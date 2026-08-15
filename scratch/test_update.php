<?php
$pdo = new PDO("mysql:host=localhost;dbname=restocloud;charset=utf8mb4", "root", "", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$stmt = $pdo->query("DESCRIBE order_details");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($columns);

// Also check the orders in draft state
$stmt = $pdo->query("SELECT id, order_id, item_status FROM order_details WHERE item_status = 'draft' LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
