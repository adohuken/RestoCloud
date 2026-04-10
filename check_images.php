<?php
require_once __DIR__ . '/config/db.php';
$stmt = $pdo->query("SELECT id, name, image_url, category_id FROM products LIMIT 20");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($products, JSON_PRETTY_PRINT);
